<?php

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\AuditEvent;
use App\Models\Condition;
use App\Models\Consent;
use App\Models\DocumentReference;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Observation;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\RecordCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private Facility $source;

    private Patient $patient;

    private User $patientUser;

    // Each request must authenticate with its own token, not the guard cached from the previous call.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->source = Facility::create(['name' => 'Source Hospital', 'type' => 'hospital', 'verification_status' => 'verified']);
        $this->patientUser = $this->user('patient', 'pat@example.test');
        $this->patient = Patient::create(['user_id' => $this->patientUser->id, 'given_name' => 'Amina', 'family_name' => 'Test', 'birth_date' => '1990-04-02']);
    }

    private function user(string $type, string $email): User
    {
        $u = (new User)->forceFill(['name' => $email, 'email' => $email, 'password' => self::PASSWORD, 'account_type' => $type, 'mfa_enrolled_at' => $type === 'patient' ? null : now()]);
        $u->save();

        return $u;
    }

    private function token(User $u): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->json('data.token');
    }

    private function staff(string $email, ?Facility $f = null): array
    {
        $u = $this->user('practitioner', $email);
        $f ??= Facility::create(['name' => 'Clinic '.$email, 'type' => 'clinic', 'verification_status' => 'verified']);
        $pr = Practitioner::create(['user_id' => $u->id, 'given_name' => 'Dr', 'family_name' => $email, 'verification_status' => 'verified']);
        PractitionerRole::create(['practitioner_id' => $pr->id, 'facility_id' => $f->id, 'role' => 'physician']);

        return [$u, $f];
    }

    private function share(Facility $f, array $cats): string
    {
        $pt = $this->token($this->patientUser);
        $this->withToken($pt)->postJson('/api/v1/patient/consents', ['facility_id' => $f->uuid, 'categories' => $cats, 'duration_hours' => 24])->assertCreated();

        return $pt;
    }

    private function base(array $extra = []): array
    {
        return $extra + ['patient_id' => $this->patient->id, 'source_facility_id' => $this->source->id, 'source_system' => 'mediflow', 'source_identifier' => uniqid(), 'clinical_date' => now()->subDays(10)];
    }

    private function records(): void
    {
        Allergy::create($this->base(['local_display' => 'Penicillin', 'severity' => 'severe', 'allergy_status' => 'active']));
        Medication::create($this->base(['local_display' => 'Metformin', 'dose_text' => '500 mg twice daily', 'medication_status' => 'active']));
        Observation::create($this->base(['local_display' => 'Hb', 'category' => 'laboratory', 'value_number' => 11.2, 'unit' => 'g/dL', 'local_code' => 'LOC-HB', 'clinical_date' => now()->subDays(900)]));
        Condition::create($this->base(['local_display' => 'Asthma', 'verification_status' => 'patient_entered', 'source_system' => null]));
    }

    private function pdf(string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".$extra);
    }

    public function test_provider_sees_only_consented_categories_with_provenance_and_honest_completeness(): void
    {
        $this->records();
        [$su, $f] = $this->staff('d@example.test');
        $this->share($f, ['allergies', 'observations']);

        $res = $this->withToken($this->token($su))->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertOk();
        $cats = collect($res->json('data'))->pluck('category')->unique()->sort()->values()->all();
        $this->assertSame(['allergies', 'observations'], $cats);

        $obs = collect($res->json('data'))->firstWhere('category', 'observations');
        $this->assertSame('Source Hospital', $obs['source']['facility']);
        $this->assertSame('g/dL', $obs['unit']);
        $this->assertSame('LOC-HB', $obs['local_code']);
        $this->assertSame('source_system', $obs['origin']);
        $this->assertTrue($obs['stale']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('category', 'allergies')['stale']);
        $this->assertFalse($res->json('meta.complete'));
        $this->assertSame(['Source Hospital'], $res->json('meta.sources'));
    }

    public function test_no_consent_wrong_facility_unknown_patient_and_patient_role_are_forbidden(): void
    {
        $this->records();
        [$su] = $this->staff('d@example.test');
        [$other, $of] = $this->staff('o@example.test');
        $this->share($of, ['allergies']);
        $t = $this->token($su);

        $a = $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertStatus(403);
        $b = $this->withToken($t)->getJson('/api/v1/provider/patients/'.Str::uuid().'/timeline')->assertStatus(403);
        $this->assertSame($a->json('error.code'), $b->json('error.code'));
        $this->assertSame($a->json('error.message'), $b->json('error.message'));
        // Changing the categories query cannot widen access.
        $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline?categories[]=medications")->assertStatus(403);
        $this->withToken($this->token($this->patientUser))->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertStatus(403);
    }

    public function test_revocation_removes_timeline_access(): void
    {
        $this->records();
        [$su, $f] = $this->staff('d@example.test');
        $pt = $this->share($f, ['allergies']);
        $t = $this->token($su);
        $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertOk();

        $id = Consent::first()->uuid;
        $this->withToken($pt)->postJson("/api/v1/patient/consents/{$id}/revoke")->assertOk();
        $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertStatus(403);
    }

    public function test_patient_sees_own_full_timeline_and_reads_are_audited(): void
    {
        $this->records();
        $res = $this->withToken($this->token($this->patientUser))->getJson('/api/v1/patient/timeline')->assertOk();

        $this->assertCount(4, $res->json('data'));
        $this->assertSame('patient_entered', collect($res->json('data'))->firstWhere('category', 'conditions')['origin']);
        $this->assertGreaterThanOrEqual(7, AuditEvent::where('authorization_basis', 'patient_self')->count());
    }

    public function test_patient_never_sees_another_patients_records(): void
    {
        $this->records();
        $u2 = $this->user('patient', 'two@example.test');
        Patient::create(['user_id' => $u2->id, 'given_name' => 'B', 'family_name' => 'B']);

        $this->withToken($this->token($u2))->getJson('/api/v1/patient/timeline')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_correction_keeps_original_and_does_not_change_record(): void
    {
        Allergy::create($this->base(['local_display' => 'Penicillin']));
        $a = Allergy::first();
        $pt = $this->token($this->patientUser);

        $this->withToken($pt)->postJson("/api/v1/patient/records/allergies/{$a->uuid}/corrections", ['kind' => 'dispute', 'reason' => 'I am not allergic'])->assertCreated();

        $c = RecordCorrection::first();
        $this->assertSame('Penicillin', $c->original_snapshot['local_display']);
        $this->assertSame('Penicillin', $a->fresh()->local_display);
        $this->assertSame('open', $c->correction_status);
        $this->assertTrue($this->withToken($pt)->getJson('/api/v1/patient/timeline')->json('data.0.correction_open'));
        $this->expectException(\LogicException::class);
        $c->update(['original_snapshot' => ['x' => 1]]);
    }

    public function test_patient_cannot_correct_someone_elses_record(): void
    {
        Allergy::create($this->base(['local_display' => 'Penicillin']));
        $u2 = $this->user('patient', 'two@example.test');
        Patient::create(['user_id' => $u2->id, 'given_name' => 'B', 'family_name' => 'B']);

        $this->withToken($this->token($u2))->postJson('/api/v1/patient/records/allergies/'.Allergy::first()->uuid.'/corrections', ['kind' => 'dispute', 'reason' => 'not mine really'])->assertStatus(404);
    }

    public function test_document_upload_download_flow_is_private_and_authorized(): void
    {
        [$su, $f] = $this->staff('d@example.test');
        [$ou] = $this->staff('o@example.test');
        $this->share($f, ['documents']);
        $t = $this->token($su);

        $up = $this->withToken($t)->post("/api/v1/provider/patients/{$this->patient->uuid}/documents", ['file' => $this->pdf(), 'title' => 'Discharge', 'document_type' => 'discharge_summary'], ['Accept' => 'application/json'])->assertCreated();
        $id = $up->json('data.id');
        $doc = DocumentReference::first();
        $this->assertStringStartsWith('documents/', $doc->storage_path);
        $this->assertStringNotContainsString('report', $doc->storage_path);
        Storage::disk('local')->assertExists($doc->storage_path);
        $this->assertArrayNotHasKey('storage_path', $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->json('data.0'));

        $dl = $this->withToken($t)->get("/api/v1/documents/{$id}/download")->assertOk();
        $dl->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $dl->headers->get('Cache-Control'));

        $this->withToken($this->token($ou))->get("/api/v1/documents/{$id}/download")->assertStatus(404);
        $this->withToken($this->token($this->patientUser))->get("/api/v1/documents/{$id}/download")->assertOk();
        $this->assertSame(2, AuditEvent::where('resource_type', 'document')->where('action', 'download')->count());
    }

    public function test_upload_abuse_is_rejected(): void
    {
        [$su, $f] = $this->staff('d@example.test');
        $this->share($f, ['documents']);
        $t = $this->token($su);
        $url = "/api/v1/provider/patients/{$this->patient->uuid}/documents";
        $meta = ['title' => 'X', 'document_type' => 'other'];

        // Disguised executable/script, wrong content with a PDF name, active PDF content, empty and oversized files.
        $html = UploadedFile::fake()->createWithContent('a.pdf', '<script>alert(1)</script>');
        $php = UploadedFile::fake()->createWithContent('a.png', '<?php system($_GET[1]);');
        $this->withToken($t)->post($url, $meta + ['file' => $html], ['Accept' => 'application/json'])->assertStatus(422);
        $this->withToken($t)->post($url, $meta + ['file' => $php], ['Accept' => 'application/json'])->assertStatus(422);
        $this->withToken($t)->post($url, $meta + ['file' => $this->pdf('/JavaScript (app.alert(1))')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->withToken($t)->post($url, $meta + ['file' => UploadedFile::fake()->createWithContent('e.pdf', '')], ['Accept' => 'application/json'])->assertStatus(422);
        config(['medipass.document_max_bytes' => 10]);
        $this->withToken($t)->post($url, $meta + ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, DocumentReference::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_without_consent_is_forbidden_and_leaves_nothing(): void
    {
        [$su] = $this->staff('d@example.test');
        $this->withToken($this->token($su))->post("/api/v1/provider/patients/{$this->patient->uuid}/documents", ['file' => $this->pdf(), 'title' => 'X', 'document_type' => 'other'], ['Accept' => 'application/json'])->assertStatus(403);
        $this->assertSame(0, DocumentReference::count());
    }

    public function test_unclean_or_withdrawn_documents_are_not_downloadable(): void
    {
        [$su, $f] = $this->staff('d@example.test');
        $this->share($f, ['documents']);
        $t = $this->token($su);
        $id = $this->withToken($t)->post("/api/v1/provider/patients/{$this->patient->uuid}/documents", ['file' => $this->pdf(), 'title' => 'Discharge', 'document_type' => 'other'], ['Accept' => 'application/json'])->json('data.id');

        DocumentReference::first()->update(['scan_status' => 'pending']);
        $this->withToken($t)->get("/api/v1/documents/{$id}/download")->assertStatus(404);
        $this->withToken($t)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline")->assertStatus(200)->assertJsonCount(0, 'data');
    }
}
