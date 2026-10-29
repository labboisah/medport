<?php

namespace App\Integrations;

use App\Models\Patient;

/** Maps already access-gated, presented records to a FHIR R4 searchset Bundle. Local codes are preserved, never translated. */
class FhirMapper
{
    public const TYPES = [
        'encounter' => 'Encounter', 'condition' => 'Condition', 'medication' => 'MedicationStatement', 'allergy' => 'AllergyIntolerance',
        'observation' => 'Observation', 'diagnostic_report' => 'DiagnosticReport', 'document_reference' => 'DocumentReference',
    ];

    public static function bundle(Patient $patient, array $items): array
    {
        $entries = [['resource' => ['resourceType' => 'Patient', 'id' => $patient->uuid]]];
        foreach ($items as $item) {
            $resource = self::resource($item, $patient->uuid);
            if (! $resource) {
                continue; // referrals are not mapped in v1
            }
            $entries[] = ['resource' => $resource];
            $entries[] = ['resource' => [
                'resourceType' => 'Provenance',
                'id' => $item['id'].'-prov',
                'target' => [['reference' => $resource['resourceType'].'/'.$item['id']]],
                'recorded' => optional($item['ingested_at'])->toIso8601String(),
                'agent' => [['who' => ['display' => $item['source']['facility'] ?? 'unknown']]],
            ]];
        }

        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => count($entries) - 1, 'entry' => $entries];
    }

    private static function code(array $i): array
    {
        return array_filter([
            'coding' => [array_filter(['system' => $i['code_system'] ?? null, 'code' => $i['local_code'] ?? $i['code'] ?? null, 'display' => $i['local_display'] ?? null])],
            'text' => $i['local_display'] ?? null,
        ]);
    }

    private static function resource(array $i, string $patientId): ?array
    {
        $type = self::TYPES[$i['type']] ?? null;
        if (! $type) {
            return null;
        }
        $date = optional($i['clinical_date'])->toIso8601String();
        $r = [
            'resourceType' => $type,
            'id' => $i['id'],
            'meta' => array_filter(['lastUpdated' => optional($i['ingested_at'])->toIso8601String(), 'source' => $i['source']['system'] ?? $i['source']['facility']]),
        ];
        $subject = ['reference' => 'Patient/'.$patientId];
        $entered = $i['record_status'] !== 'active';

        switch ($type) {
            case 'Encounter':
                $r += ['status' => 'finished', 'class' => ['code' => ['outpatient' => 'AMB', 'inpatient' => 'IMP', 'emergency' => 'EMER'][$i['encounter_class']] ?? 'AMB'], 'subject' => $subject, 'period' => array_filter(['start' => $date, 'end' => optional($i['ended_at'] ?? null)->toIso8601String()]), 'reasonCode' => [['text' => $i['reason'] ?? '']]];
                break;
            case 'Condition':
                $r += ['code' => self::code($i), 'subject' => $subject, 'clinicalStatus' => ['coding' => [['code' => $i['clinical_status']]]], 'verificationStatus' => ['coding' => [['code' => $entered ? 'entered-in-error' : 'unconfirmed']]], 'recordedDate' => $date];
                break;
            case 'MedicationStatement':
                $r += ['status' => $entered ? 'entered-in-error' : ($i['medication_status'] ?? 'unknown'), 'medicationCodeableConcept' => self::code($i), 'subject' => $subject, 'effectiveDateTime' => $date, 'dosage' => [['text' => trim(($i['dose_text'] ?? '').' '.($i['frequency_text'] ?? ''))]]];
                break;
            case 'AllergyIntolerance':
                $r += ['code' => self::code($i), 'patient' => $subject, 'recordedDate' => $date, 'reaction' => [['description' => $i['reaction_text'] ?? '']]];
                break;
            case 'Observation':
                $r += ['status' => $entered ? 'entered-in-error' : 'final', 'code' => self::code($i), 'subject' => $subject, 'effectiveDateTime' => $date];
                if ($i['value_number'] !== null) {
                    $r['valueQuantity'] = array_filter(['value' => (float) $i['value_number'], 'unit' => $i['unit']]);
                } elseif ($i['value_text'] !== null) {
                    $r['valueString'] = $i['value_text'];
                }
                break;
            case 'DiagnosticReport':
                $r += ['status' => $entered ? 'entered-in-error' : ($i['report_status'] ?? 'final'), 'code' => self::code($i) ?: ['text' => $i['report_type'] ?? ''], 'subject' => $subject, 'effectiveDateTime' => $date, 'conclusion' => $i['conclusion'] ?? null];
                break;
            case 'DocumentReference':
                $r += ['status' => 'current', 'type' => ['text' => $i['document_type'] ?? ''], 'subject' => $subject, 'date' => $date, 'description' => $i['title'] ?? null, 'content' => [['attachment' => ['contentType' => $i['mime_type'], 'size' => $i['size_bytes'], 'title' => $i['title']]]]];
                break;
        }

        return $r;
    }
}
