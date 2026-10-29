<?php

namespace Database\Seeders;

use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\PatientMatchCandidate;
use App\Models\PatientMergeEvent;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Deterministic, obviously fake data. Never load production exports here.
 * Covers: valid, missing-field, duplicate, lookalike (wrong-patient risk), merged,
 * active/expired/revoked consent, and denied-access audit scenarios.
 */
class SyntheticDataSeeder extends Seeder
{
    public function run(): void
    {
        $reviewer = User::firstOrCreate(
            ['email' => 'reviewer@synthetic.invalid'],
            ['name' => 'Synthetic Reviewer', 'password' => bin2hex(random_bytes(16))],
        );

        $hospital = $this->facility('Synthetic General Hospital', 'hospital', 'mediflow', 'verified');
        $clinic = $this->facility('Synthetic Community Clinic', 'clinic', 'portal', 'verified');
        $this->facility('Synthetic Pending Laboratory', 'lab', 'other_ehr', 'pending');

        $doctor = $this->practitioner('Synthetic', 'Doctor', 'SYN-LIC-0001', 'verified');
        $nurse = $this->practitioner('Synthetic', 'Nurse', 'SYN-LIC-0002', 'verified');
        $this->practitioner('Synthetic', 'Unverified', 'SYN-LIC-0003', 'pending');
        $this->role($doctor, $hospital, 'physician');
        $this->role($nurse, $clinic, 'nurse');

        // Valid, verified patient.
        $ada = $this->patient('Ada', 'Syntheticson', '1990-04-12', 'female', 'verified');
        $this->identifier($ada, 'synthetic_national_id', 'SYN-NID-0001', null, 'verified');
        $this->identifier($ada, 'mrn', 'SYN-MRN-A1', $hospital, 'verified');

        // Lookalike with the same name but a different birth date: must not be matched automatically.
        $adaLookalike = $this->patient('Ada', 'Syntheticson', '1985-09-30', 'female', 'unverified');
        $this->identifier($adaLookalike, 'mrn', 'SYN-MRN-A2', $clinic, 'unverified');
        $this->candidate($ada, $adaLookalike, '0.5500', ['name_match', 'birth_date_conflict'], 'rejected', $reviewer, 'Different birth date; treated as a different person.');

        // Duplicate pair awaiting human review.
        $chidi = $this->patient('Chidi', 'Syntheticman', '1978-01-05', 'male', 'verified');
        $this->identifier($chidi, 'mrn', 'SYN-MRN-C1', $hospital, 'verified');
        $chidiDup = $this->patient('Chidi', 'Syntheticman', '1978-01-05', 'male', 'unverified');
        $this->identifier($chidiDup, 'mrn', 'SYN-MRN-C2', $clinic, 'unverified');
        $this->candidate($chidi, $chidiDup, '0.8200', ['name_match', 'birth_date_match', 'sex_match'], 'pending_review');

        // Previously confirmed duplicate that was merged, with history kept.
        $emeka = $this->patient('Emeka', 'Syntheticwood', '2001-11-20', 'male', 'verified');
        $emekaOld = $this->patient('Emeka', 'Syntheticwood', '2001-11-20', 'male', 'unverified');
        $confirmed = $this->candidate($emeka, $emekaOld, '0.9600', ['name_match', 'birth_date_match', 'phone_match'], 'confirmed_same', $reviewer, 'Verified against facility record.');
        if (! $emekaOld->merged_into_patient_id) {
            $emekaOld->update(['status' => 'merged', 'merged_into_patient_id' => $emeka->id]);
            PatientMergeEvent::create([
                'surviving_patient_id' => $emeka->id,
                'merged_patient_id' => $emekaOld->id,
                'action' => 'merge',
                'match_candidate_id' => $confirmed->id,
                'performed_by_user_id' => $reviewer->id,
                'reason' => 'Confirmed duplicate (synthetic scenario).',
            ]);
        }

        // Missing data stays unknown.
        $this->patient('Unknown', 'Syntheticperson', null, null, 'unverified');

        // Consent scenarios for Ada.
        $active = $this->consent($ada, $hospital, 'SYN-CONSENT-ACTIVE', 'active', now()->addDays(30));
        $this->consent($ada, $clinic, 'SYN-CONSENT-EXPIRED', 'active', now()->subDays(2));
        $revoked = $this->consent($ada, $clinic, 'SYN-CONSENT-REVOKED', 'revoked', now()->addDays(30), now()->subDay());

        AccessGrant::firstOrCreate(
            ['consent_id' => $active->id],
            [
                'patient_id' => $ada->id, 'facility_id' => $hospital->id, 'practitioner_id' => $doctor->id,
                'scope' => ['allergies', 'medications'], 'starts_at' => now()->subDay(), 'expires_at' => $active->expires_at,
            ],
        );

        if (AuditEvent::where('correlation_id', 'SYN-AUDIT-1')->doesntExist()) {
            AuditEvent::create([
                'actor_type' => 'user', 'actor_id' => $doctor->user_id, 'facility_id' => $hospital->id,
                'patient_id' => $ada->id, 'resource_type' => 'patient_summary', 'action' => 'read',
                'purpose' => 'direct_care', 'outcome' => 'success', 'authorization_basis' => 'consent:'.$active->uuid,
                'correlation_id' => 'SYN-AUDIT-1',
            ]);
            AuditEvent::create([
                'actor_type' => 'user', 'actor_id' => $nurse->user_id, 'facility_id' => $clinic->id,
                'patient_id' => $ada->id, 'resource_type' => 'patient_summary', 'action' => 'read',
                'purpose' => 'direct_care', 'outcome' => 'denied', 'authorization_basis' => 'consent:'.$revoked->uuid.':revoked',
                'correlation_id' => 'SYN-AUDIT-2',
            ]);
        }
    }

    private function facility(string $name, string $type, string $path, string $status): Facility
    {
        return Facility::firstOrCreate(['name' => $name], [
            'type' => $type, 'state' => 'Synthetic State', 'integration_path' => $path,
            'verification_status' => $status, 'verified_at' => $status === 'verified' ? now() : null,
        ]);
    }

    private function practitioner(string $given, string $family, string $license, string $status): Practitioner
    {
        return Practitioner::firstOrCreate(['license_body' => 'SYNTHETIC', 'license_number' => $license], [
            'given_name' => $given, 'family_name' => $family,
            'verification_status' => $status, 'verified_at' => $status === 'verified' ? now() : null,
        ]);
    }

    private function role(Practitioner $p, Facility $f, string $role): void
    {
        PractitionerRole::firstOrCreate(
            ['practitioner_id' => $p->id, 'facility_id' => $f->id, 'role' => $role],
            ['status' => 'active', 'starts_at' => now()],
        );
    }

    private function patient(string $given, string $family, ?string $dob, ?string $sex, string $verification): Patient
    {
        $existing = Patient::where(['given_name' => $given, 'family_name' => $family])
            ->where('birth_date', $dob)
            ->where('identity_verification', $verification)
            ->first();

        return $existing ?? Patient::create([
            'given_name' => $given, 'family_name' => $family, 'birth_date' => $dob, 'sex' => $sex,
            'identity_verification' => $verification,
            'identity_verified_at' => $verification === 'verified' ? now() : null,
        ]);
    }

    private function identifier(Patient $p, string $system, string $value, ?Facility $f, string $status): void
    {
        PatientIdentifier::firstOrCreate(['system' => $system, 'value' => $value], [
            'patient_id' => $p->id, 'assigning_facility_id' => $f?->id, 'verification_status' => $status,
            'verified_at' => $status === 'verified' ? now() : null,
        ]);
    }

    private function candidate(Patient $a, Patient $b, string $score, array $reasons, string $status, ?User $reviewer = null, ?string $note = null): PatientMatchCandidate
    {
        return PatientMatchCandidate::firstOrCreate(
            ['patient_id' => $a->id, 'candidate_patient_id' => $b->id],
            [
                'score' => $score, 'reasons' => $reasons, 'status' => $status,
                'reviewed_by_user_id' => $reviewer?->id, 'reviewed_at' => $reviewer ? now() : null, 'review_note' => $note,
            ],
        );
    }

    private function consent(Patient $p, Facility $f, string $version, string $status, $expires, $revokedAt = null): Consent
    {
        return Consent::firstOrCreate(
            ['patient_id' => $p->id, 'recipient_facility_id' => $f->id, 'consent_text_version' => $version],
            [
                'purpose' => 'direct_care', 'categories' => ['allergies', 'medications'], 'method' => 'in_app',
                'status' => $status, 'granted_at' => now()->subDays(5), 'expires_at' => $expires,
                'revoked_at' => $revokedAt, 'revocation_reason' => $revokedAt ? 'Patient withdrew consent (synthetic).' : null,
            ],
        );
    }
}
