<?php

namespace App\Services;

use App\Models\IntegrationEndpoint;
use App\Models\PatientIdentifier;
use Illuminate\Support\Str;

/**
 * Applies one neutral item from a partner. Rules:
 * - repeated deliveries never duplicate (unique per source facility + source identifier);
 * - an update older than or equal to what is stored is skipped, so stale data cannot overwrite newer data;
 * - the partner is the authority only for records it created; staff-entered, patient-entered or
 *   entered-in-error records are never overwritten and are reported as conflicts.
 */
class IngestService
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const STALE = 'skipped_stale';

    public function apply(IntegrationEndpoint $endpoint, array $item): string
    {
        $patient = PatientIdentifier::where('system', $item['identifier']['system'])->where('value', $item['identifier']['value'])->first()?->patient;
        if (! $patient || $patient->status !== 'active') {
            return 'patient_unmatched'; // identical for unknown and inactive, so deliveries cannot probe who exists
        }

        $class = RecordPresenter::MODELS[$item['category']][1];
        $existing = $class::where('source_facility_id', $endpoint->facility_id)->where('source_identifier', $item['source_identifier'])->first();

        if (! $existing) {
            $class::create($item['attrs'] + [
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'source_facility_id' => $endpoint->facility_id,
                'source_system' => $endpoint->sourceSystem(),
                'source_identifier' => $item['source_identifier'],
                'source_updated_at' => $item['source_updated_at'],
                'clinical_date' => $item['clinical_date'],
                'verification_status' => 'unverified',
            ]);

            return self::CREATED;
        }

        if ($existing->patient_id !== $patient->id) {
            return 'conflict_patient_mismatch';
        }
        if ($existing->source_system !== $endpoint->sourceSystem() || $existing->record_status !== 'active') {
            return 'conflict_local_record';
        }
        if ($existing->source_updated_at && $item['source_updated_at']->lessThanOrEqualTo($existing->source_updated_at)) {
            return self::STALE;
        }

        $existing->update($item['attrs'] + ['source_updated_at' => $item['source_updated_at'], 'clinical_date' => $item['clinical_date'], 'ingested_at' => now()]);

        return self::UPDATED;
    }
}
