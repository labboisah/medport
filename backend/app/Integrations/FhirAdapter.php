<?php

namespace App\Integrations;

use Carbon\Carbon;
use Throwable;

/** Reads a FHIR R4 collection/transaction Bundle. Local codes and wording are kept as supplied. */
class FhirAdapter implements PartnerAdapter
{
    public function normalize(array $payload): array
    {
        if (($payload['resourceType'] ?? null) !== 'Bundle' || ! is_array($payload['entry'] ?? null) || $payload['entry'] === []) {
            throw new MalformedPayload('Expected a FHIR Bundle with at least one entry.');
        }

        $items = [];
        foreach ($payload['entry'] as $entry) {
            $resource = is_array($entry) ? ($entry['resource'] ?? null) : null;
            if (! is_array($resource) || ! is_string($resource['resourceType'] ?? null)) {
                $items[] = ['error' => 'malformed_resource'];

                continue;
            }
            try {
                $items[] = $this->map($resource);
            } catch (Throwable) {
                $items[] = ['error' => 'malformed_resource'];
            }
        }

        return $items;
    }

    private function map(array $r): array
    {
        $type = $r['resourceType'];
        $id = $r['id'] ?? null;
        $updated = $r['meta']['lastUpdated'] ?? null;
        if (! is_string($id) || $id === '') {
            return ['error' => 'missing_id'];
        }
        if (! is_string($updated)) {
            return ['error' => 'missing_last_updated'];
        }

        $ref = $r['subject'] ?? $r['patient'] ?? null;
        $identifier = is_array($ref) ? ($ref['identifier'] ?? null) : null;
        if (! is_array($identifier) || ! is_string($identifier['system'] ?? null) || ! is_string($identifier['value'] ?? null)) {
            return ['error' => 'missing_patient_identifier'];
        }

        $base = ['source_identifier' => $id, 'source_updated_at' => Carbon::parse($updated), 'identifier' => ['system' => $identifier['system'], 'value' => $identifier['value']]];

        $mapped = match ($type) {
            'Observation' => $this->observation($r),
            'Condition' => $this->condition($r),
            'AllergyIntolerance' => $this->allergy($r),
            'MedicationStatement' => $this->medication($r),
            'Encounter' => $this->encounter($r),
            'DiagnosticReport' => $this->report($r),
            default => null,
        };

        return $mapped ? $base + $mapped : ['error' => 'unsupported_resource'];
    }

    private function coding(array $r, string $key = 'code'): array
    {
        $cc = $r[$key] ?? [];
        $c = $cc['coding'][0] ?? [];
        $display = $c['display'] ?? $cc['text'] ?? null;
        if (! is_string($display) || $display === '') {
            throw new MalformedPayload('No display text.');
        }

        // The local code and wording are stored as supplied; normalized code fields are only filled when the source supplied them.
        return ['local_code' => $c['code'] ?? null, 'local_display' => $display, 'code_system' => $c['system'] ?? null, 'code' => $c['code'] ?? null];
    }

    private function date(array $r, string ...$keys): Carbon
    {
        foreach ($keys as $k) {
            if (is_string($r[$k] ?? null)) {
                return Carbon::parse($r[$k]);
            }
        }
        throw new MalformedPayload('No clinical date.');
    }

    private function status(?string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : 'unknown';
    }

    private function observation(array $r): array
    {
        $cat = $r['category'][0]['coding'][0]['code'] ?? null;
        $range = $r['referenceRange'][0] ?? [];
        $q = $r['valueQuantity'] ?? null;

        return ['category' => 'observations', 'clinical_date' => $this->date($r, 'effectiveDateTime', 'issued'), 'attrs' => $this->coding($r) + [
            'category' => match ($cat) {
                'vital-signs' => 'vital_sign', 'laboratory' => 'laboratory', default => 'other'
            },
            'value_number' => is_numeric($q['value'] ?? null) ? $q['value'] : null,
            'value_text' => is_string($r['valueString'] ?? null) ? $r['valueString'] : null,
            'unit' => $q['unit'] ?? null,
            'reference_low' => $range['low']['value'] ?? null,
            'reference_high' => $range['high']['value'] ?? null,
            'interpretation' => isset($r['interpretation'][0]['coding'][0]['code']) ? substr((string) $r['interpretation'][0]['coding'][0]['code'], 0, 20) : null,
        ]];
    }

    private function condition(array $r): array
    {
        return ['category' => 'conditions', 'clinical_date' => $this->date($r, 'onsetDateTime', 'recordedDate'), 'attrs' => $this->coding($r) + [
            'clinical_status' => $this->status($r['clinicalStatus']['coding'][0]['code'] ?? null, ['active', 'resolved']),
        ]];
    }

    private function allergy(array $r): array
    {
        $reaction = $r['reaction'][0] ?? [];

        return ['category' => 'allergies', 'clinical_date' => $this->date($r, 'onsetDateTime', 'recordedDate'), 'attrs' => $this->coding($r) + [
            'reaction_text' => $reaction['manifestation'][0]['text'] ?? null,
            'severity' => in_array($reaction['severity'] ?? null, ['mild', 'moderate', 'severe'], true) ? $reaction['severity'] : null,
            'allergy_status' => $this->status($r['clinicalStatus']['coding'][0]['code'] ?? null, ['active', 'inactive']),
        ]];
    }

    private function medication(array $r): array
    {
        $d = $r['dosage'][0] ?? [];

        return ['category' => 'medications', 'clinical_date' => $this->date($r, 'effectiveDateTime', 'dateAsserted'), 'attrs' => $this->coding($r, 'medicationCodeableConcept') + [
            'dose_text' => $d['text'] ?? null,
            'route' => $d['route']['text'] ?? null,
            'medication_status' => $this->status($r['status'] ?? null, ['active', 'stopped', 'completed']),
        ]];
    }

    private function encounter(array $r): array
    {
        $class = match ($r['class']['code'] ?? null) {
            'AMB' => 'outpatient', 'IMP' => 'inpatient', 'EMER' => 'emergency', default => null
        };
        if (! $class) {
            return ['error' => 'unsupported_value'];
        }

        return ['category' => 'encounters', 'clinical_date' => $this->date($r['period'] ?? [], 'start'), 'attrs' => [
            'encounter_class' => $class,
            'ended_at' => isset($r['period']['end']) ? Carbon::parse($r['period']['end']) : null,
            'reason' => $r['reasonCode'][0]['text'] ?? null,
        ]];
    }

    private function report(array $r): array
    {
        $cat = $r['category'][0]['coding'][0]['code'] ?? null;

        return ['category' => 'diagnostic_reports', 'clinical_date' => $this->date($r, 'effectiveDateTime', 'issued'), 'attrs' => $this->coding($r) + [
            'report_type' => match ($cat) {
                'LAB' => 'laboratory', 'RAD' => 'imaging', default => 'other'
            },
            'conclusion' => $r['conclusion'] ?? null,
            'report_status' => in_array($r['status'] ?? null, ['preliminary', 'final', 'amended'], true) ? $r['status'] : 'final',
        ]];
    }
}
