<?php

namespace Database\Seeders;

use App\Models\Allergy;
use App\Models\Condition;
use App\Models\DiagnosticReport;
use App\Models\DocumentReference;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Observation;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\RecordCorrection;
use App\Models\Referral;
use Illuminate\Database\Seeder;

/**
 * Synthetic clinical fixtures. Requires SyntheticDataSeeder. Invented values only,
 * flagged SYN-; nothing here is clinical guidance.
 */
class SyntheticClinicalSeeder extends Seeder
{
    public function run(): void
    {
        $hospital = Facility::where('name', 'Synthetic General Hospital')->firstOrFail();
        $clinic = Facility::where('name', 'Synthetic Community Clinic')->firstOrFail();
        $doctor = Practitioner::where('license_number', 'SYN-LIC-0001')->firstOrFail();
        $ada = Patient::where(['given_name' => 'Ada', 'family_name' => 'Syntheticson', 'identity_verification' => 'verified'])->firstOrFail();
        $lookalike = Patient::where(['given_name' => 'Ada', 'family_name' => 'Syntheticson', 'identity_verification' => 'unverified'])->firstOrFail();

        $base = fn (Facility $f, string $id, string $date, string $verification = 'clinician_verified') => [
            'source_facility_id' => $f->id, 'source_system' => 'synthetic', 'source_identifier' => $id,
            'clinical_date' => $date, 'verification_status' => $verification,
            'ingested_at' => '2026-10-20 09:00:00',
        ];

        // Valid, verified encounter with linked items.
        $visit = Encounter::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-ENC-1'],
            $base($hospital, 'SYN-ENC-1', '2026-10-01 10:00:00') + [
                'patient_id' => $ada->id, 'encounter_class' => 'outpatient', 'author_practitioner_id' => $doctor->id,
            ],
        );

        Condition::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-COND-1'],
            $base($hospital, 'SYN-COND-1', '2026-10-01 10:00:00') + [
                'patient_id' => $ada->id, 'encounter_id' => $visit->id, 'local_code' => 'SYN-HTN', 'local_display' => 'Synthetic hypertension',
                'clinical_status' => 'active',
            ],
        );

        // Valid lab observation: original value and unit preserved.
        $report = DiagnosticReport::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-REP-1'],
            $base($hospital, 'SYN-REP-1', '2026-10-01 12:00:00') + [
                'patient_id' => $ada->id, 'encounter_id' => $visit->id, 'report_type' => 'laboratory', 'local_display' => 'Synthetic glucose panel',
            ],
        );
        Observation::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-OBS-1'],
            $base($hospital, 'SYN-OBS-1', '2026-10-01 12:00:00') + [
                'patient_id' => $ada->id, 'diagnostic_report_id' => $report->id, 'category' => 'laboratory',
                'local_display' => 'Synthetic glucose', 'value_number' => 5.4, 'unit' => 'mmol/L', 'reference_low' => 3.9, 'reference_high' => 5.6,
            ],
        );

        // Missing data: unknown severity/status, no source identifier, patient-entered and unverified.
        Allergy::firstOrCreate(
            ['patient_id' => $ada->id, 'local_display' => 'Synthetic allergen A', 'source_facility_id' => $clinic->id],
            ['clinical_date' => '2026-09-15 08:00:00', 'ingested_at' => '2026-10-20 09:00:00', 'verification_status' => 'patient_entered'],
        );

        // Stale medication: old clinical date, still marked active.
        Medication::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-MED-OLD'],
            $base($hospital, 'SYN-MED-OLD', '2024-01-10 09:00:00', 'source_verified') + [
                'patient_id' => $ada->id, 'local_display' => 'Synthetic medication X', 'dose_text' => '1 tablet twice daily', 'medication_status' => 'active',
            ],
        );

        // Conflicting: two facilities disagree about the same allergen's status; both are kept visible.
        foreach ([[$hospital, 'SYN-ALG-H', 'active'], [$clinic, 'SYN-ALG-C', 'inactive']] as [$f, $id, $status]) {
            Allergy::firstOrCreate(
                ['source_facility_id' => $f->id, 'source_identifier' => $id],
                $base($f, $id, '2026-08-01 09:00:00') + [
                    'patient_id' => $ada->id, 'local_display' => 'Synthetic allergen B', 'allergy_status' => $status, 'severity' => 'moderate',
                ],
            );
        }

        // Wrong-patient entry: recorded against the lookalike, marked entered-in-error, with a correction trail.
        $wrong = Observation::firstOrCreate(
            ['source_facility_id' => $clinic->id, 'source_identifier' => 'SYN-OBS-WRONG'],
            $base($clinic, 'SYN-OBS-WRONG', '2026-10-05 11:00:00', 'unverified') + [
                'patient_id' => $lookalike->id, 'category' => 'vital_sign', 'local_display' => 'Synthetic weight',
                'value_number' => 70, 'unit' => 'kg', 'record_status' => 'entered_in_error',
            ],
        );
        if ($wrong->corrections()->doesntExist()) {
            RecordCorrection::create([
                'patient_id' => $lookalike->id, 'record_type' => 'observation', 'record_id' => $wrong->id,
                'kind' => 'entered_in_error', 'correction_status' => 'accepted',
                'original_snapshot' => $wrong->only(['local_display', 'value_number', 'unit', 'patient_id']),
                'reason' => 'Recorded against the wrong patient (synthetic scenario).', 'resolved_at' => '2026-10-06 09:00:00',
                'resolution_note' => 'Source facility confirmed; original retained.',
            ]);
        }

        // Corrected value: open dispute keeps the original snapshot.
        $disputed = Observation::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-OBS-DISPUTE'],
            $base($hospital, 'SYN-OBS-DISPUTE', '2026-10-02 08:00:00') + [
                'patient_id' => $ada->id, 'category' => 'vital_sign', 'local_display' => 'Synthetic weight',
                'value_number' => 7.0, 'unit' => 'kg',
            ],
        );
        if ($disputed->corrections()->doesntExist()) {
            RecordCorrection::create([
                'patient_id' => $ada->id, 'record_type' => 'observation', 'record_id' => $disputed->id,
                'kind' => 'dispute', 'correction_status' => 'routed_to_source',
                'original_snapshot' => $disputed->only(['local_display', 'value_number', 'unit']),
                'proposed_change' => ['value_number' => 70.0],
                'reason' => 'Patient reports a decimal-place error (synthetic scenario).',
            ]);
        }

        DocumentReference::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-DOC-1'],
            $base($hospital, 'SYN-DOC-1', '2026-10-01 15:00:00') + [
                'patient_id' => $ada->id, 'encounter_id' => $visit->id, 'document_type' => 'discharge_summary',
                'title' => 'Synthetic discharge summary', 'mime_type' => 'application/pdf', 'scan_status' => 'pending',
            ],
        );

        Referral::firstOrCreate(
            ['source_facility_id' => $hospital->id, 'source_identifier' => 'SYN-REF-1'],
            $base($hospital, 'SYN-REF-1', '2026-10-02 09:00:00') + [
                'patient_id' => $ada->id, 'to_facility_id' => $clinic->id, 'reason' => 'Synthetic follow-up',
            ],
        );
    }
}
