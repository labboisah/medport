<?php

namespace App\Integrations;

use Carbon\Carbon;
use Throwable;

/**
 * SYNTHETIC proof-of-concept adapter for a Mediflow-shaped delivery. The payload shape below is an
 * assumption used for contract tests with synthetic data. It is NOT the real Mediflow interface and
 * must be replaced once a partner sandbox, agreement and specification exist.
 *
 * { "schema": "mediflow-synthetic-1", "patient": {"system","value"},
 *   "records": [ {"id","updated_at","type": lab_result|vital|diagnosis|allergy|medication|visit, "occurred_at", "code","name","value","unit","status"} ] }
 */
class MediflowSyntheticAdapter implements PartnerAdapter
{
    public function normalize(array $payload): array
    {
        $patient = $payload['patient'] ?? null;
        if (($payload['schema'] ?? null) !== 'mediflow-synthetic-1' || ! is_array($patient) || ! is_string($patient['system'] ?? null) || ! is_string($patient['value'] ?? null) || ! is_array($payload['records'] ?? null) || $payload['records'] === []) {
            throw new MalformedPayload('Unrecognised delivery.');
        }

        $items = [];
        foreach ($payload['records'] as $rec) {
            try {
                $items[] = $this->map(is_array($rec) ? $rec : [], $patient);
            } catch (Throwable) {
                $items[] = ['error' => 'malformed_resource'];
            }
        }

        return $items;
    }

    private function map(array $rec, array $patient): array
    {
        if (! is_string($rec['id'] ?? null) || ! is_string($rec['updated_at'] ?? null)) {
            return ['error' => 'missing_id'];
        }
        $date = Carbon::parse($rec['occurred_at']);
        $name = $rec['name'] ?? null;
        $coding = ['local_code' => $rec['code'] ?? null, 'local_display' => $name, 'code_system' => null, 'code' => null];
        $base = ['source_identifier' => $rec['id'], 'source_updated_at' => Carbon::parse($rec['updated_at']), 'identifier' => ['system' => $patient['system'], 'value' => $patient['value']], 'clinical_date' => $date];

        if ($rec['type'] !== 'visit' && (! is_string($name) || $name === '')) {
            return ['error' => 'malformed_resource'];
        }

        return $base + match ($rec['type'] ?? null) {
            'lab_result', 'vital' => ['category' => 'observations', 'attrs' => $coding + [
                'category' => $rec['type'] === 'vital' ? 'vital_sign' : 'laboratory',
                'value_number' => is_numeric($rec['value'] ?? null) ? $rec['value'] : null,
                'value_text' => is_string($rec['value'] ?? null) && ! is_numeric($rec['value']) ? $rec['value'] : null,
                'unit' => $rec['unit'] ?? null,
            ]],
            'diagnosis' => ['category' => 'conditions', 'attrs' => $coding + ['clinical_status' => in_array($rec['status'] ?? null, ['active', 'resolved'], true) ? $rec['status'] : 'unknown']],
            'allergy' => ['category' => 'allergies', 'attrs' => $coding + ['allergy_status' => in_array($rec['status'] ?? null, ['active', 'inactive'], true) ? $rec['status'] : 'unknown']],
            'medication' => ['category' => 'medications', 'attrs' => $coding + ['dose_text' => $rec['dose'] ?? null, 'medication_status' => in_array($rec['status'] ?? null, ['active', 'stopped', 'completed'], true) ? $rec['status'] : 'unknown']],
            'visit' => ['category' => 'encounters', 'attrs' => ['encounter_class' => in_array($rec['class'] ?? null, ['outpatient', 'inpatient', 'emergency'], true) ? $rec['class'] : 'outpatient', 'reason' => $rec['reason'] ?? null]],
            default => ['error' => 'unsupported_resource'],
        };
    }
}
