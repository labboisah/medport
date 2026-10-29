<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\PatientMatchCandidate;
use App\Models\PatientMergeEvent;
use Database\Seeders\SyntheticDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_builds_scenarios_and_is_idempotent(): void
    {
        $this->seed(SyntheticDataSeeder::class);
        $counts = [Patient::count(), Facility::count(), Consent::count(), AuditEvent::count(), PatientIdentifier::count()];

        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame($counts, [Patient::count(), Facility::count(), Consent::count(), AuditEvent::count(), PatientIdentifier::count()]);
    }

    public function test_duplicates_are_left_for_review_not_merged(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $pending = PatientMatchCandidate::where('status', 'pending_review')->get();
        $this->assertCount(1, $pending);
        $this->assertSame('active', $pending->first()->candidate->status);
        $this->assertNull($pending->first()->candidate->merged_into_patient_id);
    }

    public function test_merge_history_is_recorded_and_append_only(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $merged = Patient::where('status', 'merged')->firstOrFail();
        $this->assertNotNull($merged->merged_into_patient_id);

        $event = PatientMergeEvent::firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['reason' => 'tampered']);
    }

    public function test_audit_events_cannot_be_updated_or_deleted(): void
    {
        $this->seed(SyntheticDataSeeder::class);
        $event = AuditEvent::firstOrFail();

        try {
            $event->update(['outcome' => 'tampered']);
            $this->fail('Update should be blocked.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_only_unexpired_unrevoked_consent_is_effective(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame(3, Consent::count());
        $effective = Consent::effective()->get();
        $this->assertCount(1, $effective);
        $this->assertSame('SYN-CONSENT-ACTIVE', $effective->first()->consent_text_version);
        $this->assertCount(1, AccessGrant::effective()->get());
    }

    public function test_identifier_system_and_value_must_be_unique(): void
    {
        $patient = Patient::create(['given_name' => 'A', 'family_name' => 'B']);
        PatientIdentifier::create(['patient_id' => $patient->id, 'system' => 'mrn', 'value' => 'X1']);

        $this->expectException(QueryException::class);
        PatientIdentifier::create(['patient_id' => $patient->id, 'system' => 'mrn', 'value' => 'X1']);
    }

    public function test_consent_requires_expiry_and_recipient(): void
    {
        $patient = Patient::create(['given_name' => 'A', 'family_name' => 'B']);

        $this->expectException(QueryException::class);
        Consent::create([
            'patient_id' => $patient->id, 'categories' => ['allergies'],
            'consent_text_version' => 'v', 'method' => 'in_app', 'granted_at' => now(),
        ]);
    }

    public function test_patient_with_identifiers_cannot_be_deleted(): void
    {
        $patient = Patient::create(['given_name' => 'A', 'family_name' => 'B']);
        PatientIdentifier::create(['patient_id' => $patient->id, 'system' => 'mrn', 'value' => 'X2']);

        $this->expectException(QueryException::class);
        $patient->delete();
    }

    public function test_missing_demographics_stay_null(): void
    {
        $patient = Patient::create(['given_name' => 'Unknown', 'family_name' => 'Person'])->fresh();

        $this->assertNull($patient->birth_date);
        $this->assertNull($patient->sex);
        $this->assertSame('unverified', $patient->identity_verification);
    }
}
