<?php

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\DocumentReference;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Observation;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\RecordCorrection;
use Database\Seeders\SyntheticDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ClinicalRecordTest extends TestCase
{
    use RefreshDatabase;

    private function facility(): Facility
    {
        return Facility::create(['name' => 'F', 'type' => 'clinic']);
    }

    private function observation(array $overrides = []): Observation
    {
        $patient = Patient::first() ?? Patient::create(['given_name' => 'A', 'family_name' => 'B']);

        return Observation::create($overrides + [
            'patient_id' => $patient->id, 'source_facility_id' => ($overrides['source_facility_id'] ?? $this->facility()->id),
            'category' => 'laboratory', 'local_display' => 'Test', 'clinical_date' => now(),
        ]);
    }

    public function test_seeder_is_idempotent_for_clinical_data(): void
    {
        $this->seed(SyntheticDataSeeder::class);
        $before = [Observation::count(), Allergy::count(), RecordCorrection::count()];

        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame($before, [Observation::count(), Allergy::count(), RecordCorrection::count()]);
    }

    public function test_source_facility_and_clinical_date_are_required(): void
    {
        $patient = Patient::create(['given_name' => 'A', 'family_name' => 'B']);

        $this->expectException(QueryException::class);
        Observation::create(['patient_id' => $patient->id, 'category' => 'laboratory', 'local_display' => 'x', 'clinical_date' => now()]);
    }

    public function test_original_display_is_required_and_normalized_code_is_optional(): void
    {
        $facility = $this->facility();
        $obs = $this->observation(['source_facility_id' => $facility->id, 'code_system' => 'LOINC', 'code' => 'x']);

        $this->assertSame('Test', $obs->fresh()->local_display);

        $this->expectException(QueryException::class);
        $this->observation(['source_facility_id' => $facility->id, 'local_display' => null]);
    }

    public function test_duplicate_submission_from_same_source_is_rejected(): void
    {
        $facility = $this->facility();
        $this->observation(['source_facility_id' => $facility->id, 'source_identifier' => 'DUP-1']);

        $this->expectException(QueryException::class);
        $this->observation(['source_facility_id' => $facility->id, 'source_identifier' => 'DUP-1']);
    }

    public function test_original_value_and_unit_are_preserved_exactly(): void
    {
        $obs = $this->observation(['value_number' => 5.4, 'unit' => 'mmol/L'])->fresh();

        $this->assertSame('5.4000', $obs->value_number);
        $this->assertSame('mmol/L', $obs->unit);
    }

    public function test_dispute_keeps_the_original_and_snapshot_is_write_once(): void
    {
        $this->seed(SyntheticDataSeeder::class);
        $dispute = RecordCorrection::where('kind', 'dispute')->firstOrFail();

        $this->assertSame('7.0000', Observation::where('source_identifier', 'SYN-OBS-DISPUTE')->first()->value_number);
        $this->assertSame(70.0, (float) $dispute->proposed_change['value_number']);

        $dispute->update(['correction_status' => 'accepted']);

        $this->expectException(LogicException::class);
        $dispute->update(['original_snapshot' => ['tampered' => true]]);
    }

    public function test_corrections_cannot_be_deleted(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->expectException(LogicException::class);
        RecordCorrection::firstOrFail()->delete();
    }

    public function test_entered_in_error_is_hidden_from_current_but_retained(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame(1, Observation::where('record_status', 'entered_in_error')->count());
        $this->assertSame(0, Observation::current()->where('source_identifier', 'SYN-OBS-WRONG')->count());
        $wrong = Observation::where('source_identifier', 'SYN-OBS-WRONG')->firstOrFail();
        $this->assertSame('accepted', $wrong->corrections()->first()->correction_status);
    }

    public function test_conflicting_sources_are_both_kept(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $statuses = Allergy::where('local_display', 'Synthetic allergen B')->pluck('allergy_status')->sort()->values()->all();
        $this->assertSame(['active', 'inactive'], $statuses);
    }

    public function test_stale_scope_uses_caller_supplied_threshold_and_missing_values_stay_unknown(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame(1, Medication::stale(365)->count());
        $allergy = Allergy::where('local_display', 'Synthetic allergen A')->firstOrFail();
        $this->assertNull($allergy->severity);
        $this->assertNull($allergy->source_identifier);
        $this->assertSame('unknown', $allergy->allergy_status);
        $this->assertFalse($allergy->isVerified());
    }

    public function test_document_storage_path_is_hidden_from_serialization(): void
    {
        $this->seed(SyntheticDataSeeder::class);
        $doc = DocumentReference::firstOrFail();
        $doc->forceFill(['storage_path' => 'private/secret.pdf'])->save();

        $this->assertArrayNotHasKey('storage_path', $doc->fresh()->toArray());
    }

    public function test_facility_with_records_cannot_be_deleted(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->expectException(QueryException::class);
        Facility::where('name', 'Synthetic General Hospital')->firstOrFail()->delete();
    }

    public function test_seeded_data_contains_no_real_looking_identifiers(): void
    {
        $this->seed(SyntheticDataSeeder::class);

        $this->assertSame(0, PatientIdentifier::where('value', 'not like', 'SYN-%')->count());
        $this->assertTrue(Patient::whereNotNull('birth_date')->get()->every(fn ($p) => $p->birth_date->year >= 1900));
        $this->assertSame(0, Observation::whereNotNull('source_identifier')->where('source_identifier', 'not like', 'SYN-%')->count());
    }
}
