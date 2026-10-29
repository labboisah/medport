<?php

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\IntegrationEndpoint;
use App\Models\Observation;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\IngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private Facility $facility;

    private Patient $patient;

    private string $key = 'mpk_test_key_1234567890';

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->facility = Facility::create(['name' => 'Partner Lab', 'type' => 'lab', 'verification_status' => 'verified']);
        $this->patient = Patient::create(['given_name' => 'Amina', 'family_name' => 'Test', 'birth_date' => '1990-04-02']);
        PatientIdentifier::create(['patient_id' => $this->patient->id, 'system' => 'urn:demo:mrn', 'value' => 'MRN-1']);
    }

    private function endpoint(array $over = []): IntegrationEndpoint
    {
        return IntegrationEndpoint::create($over + [
            'facility_id' => $this->facility->id, 'name' => 'lab-feed', 'protocol' => 'fhir_rest', 'environment' => 'sandbox', 'status' => 'active',
            'scopes' => ['write:observations', 'write:allergies'], 'api_key_hash' => hash('sha256', $this->key), 'key_expires_at' => now()->addDays(30),
        ]);
    }

    private function obs(string $id = 'obs-1', string $updated = '2026-01-10T10:00:00Z', float $value = 5.5, string $mrn = 'MRN-1'): array
    {
        return ['resourceType' => 'Observation', 'id' => $id, 'meta' => ['lastUpdated' => $updated], 'status' => 'final',
            'category' => [['coding' => [['code' => 'laboratory']]]],
            'code' => ['coding' => [['system' => 'urn:local', 'code' => 'GLU', 'display' => 'Glucose']]],
            'subject' => ['identifier' => ['system' => 'urn:demo:mrn', 'value' => $mrn]],
            'effectiveDateTime' => '2026-01-09T08:00:00Z', 'valueQuantity' => ['value' => $value, 'unit' => 'mmol/L']];
    }

    private function bundle(array ...$resources): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => array_map(fn ($r) => ['resource' => $r], $resources)];
    }

    private function push(array $body, string $idem = 'delivery-0001', ?string $key = null)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($key ?? $this->key), 'Idempotency-Key' => $idem])->postJson('/api/v1/integrations/records', $body);
    }

    public function test_bad_expired_disabled_keys_and_unverified_facility_all_look_identical(): void
    {
        $e = $this->endpoint();
        $bad = $this->push($this->bundle($this->obs()), 'delivery-0001', 'wrong');
        $bad->assertStatus(401);
        $e->update(['status' => 'disabled']);
        $this->assertSame($bad->json('error.message'), $this->push($this->bundle($this->obs()))->assertStatus(401)->json('error.message'));
        $e->update(['status' => 'active', 'key_expires_at' => now()->subDay()]);
        $this->push($this->bundle($this->obs()))->assertStatus(401);
        $e->update(['key_expires_at' => now()->addDay()]);
        $this->facility->update(['verification_status' => 'pending']);
        $this->push($this->bundle($this->obs()))->assertStatus(401);
        $this->assertSame(0, Observation::count());
    }

    public function test_ingest_creates_unverified_record_and_duplicate_delivery_is_replayed(): void
    {
        $this->endpoint();
        $first = $this->push($this->bundle($this->obs()))->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame('unverified', Observation::first()->verification_status);

        $again = $this->push($this->bundle($this->obs()))->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $again->json());
        $this->assertSame(1, Observation::count());

        $this->push($this->bundle($this->obs('obs-2')))->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$this->key])->postJson('/api/v1/integrations/records', $this->bundle($this->obs()))->assertStatus(422);
    }

    public function test_stale_updates_are_skipped_and_newer_ones_applied(): void
    {
        $this->endpoint();
        $this->push($this->bundle($this->obs()), 'delivery-0001')->assertOk();
        $this->push($this->bundle($this->obs('obs-1', '2026-01-05T10:00:00Z', 9.9)), 'delivery-0002')->assertOk()->assertJsonPath('data.skipped_stale', 1);
        $this->assertEquals(5.5, Observation::first()->value_number);
        $this->push($this->bundle($this->obs('obs-1', '2026-02-01T10:00:00Z', 7.1)), 'delivery-0003')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertEquals(7.1, Observation::first()->value_number);
        $this->assertSame(1, Observation::count());
    }

    public function test_out_of_scope_malformed_and_unknown_patient_are_handled(): void
    {
        $this->endpoint();
        $cond = ['resourceType' => 'Condition', 'id' => 'c1', 'meta' => ['lastUpdated' => '2026-01-10T10:00:00Z'], 'code' => ['text' => 'Asthma'], 'subject' => ['identifier' => ['system' => 'urn:demo:mrn', 'value' => 'MRN-1']], 'recordedDate' => '2026-01-01'];
        $this->push($this->bundle($cond))->assertStatus(403)->assertJsonPath('error.code', 'scope_denied');
        $this->push(['hello' => 'world'], 'delivery-0002')->assertStatus(422)->assertJsonPath('error.code', 'malformed_payload');

        $partial = $this->push($this->bundle($this->obs('o1'), $this->obs('o2', mrn: 'NOPE')), 'delivery-0003')->assertOk();
        $partial->assertJsonPath('data.status', 'partial')->assertJsonPath('data.created', 1)->assertJsonPath('data.rejected', 1)->assertJsonPath('data.errors.0.code', 'patient_unmatched');
        $this->assertStringNotContainsString('NOPE', json_encode($partial->json()));
        $this->assertStringNotContainsString('NOPE', json_encode(SyncJob::first()->toArray()));
    }

    public function test_a_staff_entered_record_is_never_overwritten(): void
    {
        $this->endpoint();
        $this->push($this->bundle($this->obs()))->assertOk();
        Observation::first()->update(['source_system' => null]);
        $this->push($this->bundle($this->obs('obs-1', '2026-03-01T10:00:00Z', 1.0)), 'delivery-0002')->assertOk()->assertJsonPath('data.rejected', 1);
        $this->assertEquals(5.5, Observation::first()->value_number);
    }

    public function test_synthetic_mediflow_adapter(): void
    {
        $this->endpoint(['protocol' => 'adapter', 'name' => 'mediflow', 'scopes' => ['write:allergies', 'write:observations']]);
        $body = ['schema' => 'mediflow-synthetic-1', 'patient' => ['system' => 'urn:demo:mrn', 'value' => 'MRN-1'], 'records' => [
            ['id' => 'a1', 'updated_at' => '2026-01-10T10:00:00Z', 'type' => 'allergy', 'occurred_at' => '2025-05-01', 'code' => 'PEN', 'name' => 'Penicillin', 'status' => 'active'],
            ['id' => 'v1', 'updated_at' => '2026-01-10T10:00:00Z', 'type' => 'vital', 'occurred_at' => '2026-01-09', 'code' => 'BP', 'name' => 'Systolic', 'value' => '120', 'unit' => 'mmHg'],
        ]];
        $this->push($body)->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame('mediflow', Allergy::first()->source_system);
        $this->assertSame('Penicillin', Allergy::first()->local_display);
    }

    public function test_processing_failure_backs_off_dead_letters_and_admin_can_replay(): void
    {
        $e = $this->endpoint();
        config(['medipass.integration_max_attempts' => 2]);
        $this->app->bind(IngestService::class, fn () => new class extends IngestService
        {
            public function apply(IntegrationEndpoint $endpoint, array $item): string
            {
                throw new \RuntimeException('outage');
            }
        });

        $this->push($this->bundle($this->obs()))->assertStatus(503);
        $job = SyncJob::first();
        $this->assertSame('failed', $job->sync_status);
        $this->push($this->bundle($this->obs()))->assertStatus(503)->assertHeader('Retry-After');
        $this->assertSame(1, $job->fresh()->attempts);

        $this->travel(31)->seconds();
        $this->push($this->bundle($this->obs()))->assertStatus(503);
        $this->assertSame('dead_letter', $job->fresh()->sync_status);
        $this->push($this->bundle($this->obs()))->assertStatus(409);

        $admin = (new User)->forceFill(['name' => 'a', 'email' => 'admin@example.test', 'password' => self::PASSWORD, 'account_type' => 'admin', 'mfa_enrolled_at' => now()]);
        $admin->save();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->json('data.token');
        $this->withToken($token)->getJson("/api/v1/admin/integrations/{$e->uuid}/jobs")->assertOk()->assertJsonPath('data.0.status', 'dead_letter');
        $this->withToken($token)->postJson("/api/v1/admin/sync-jobs/{$job->uuid}/replay")->assertOk()->assertJsonPath('data.status', 'queued');

        // Recovery: the outage ends and the partner redelivers with the same key.
        $this->app->forgetInstance(IngestService::class);
        $this->app->bind(IngestService::class, fn () => new IngestService);
        $this->push($this->bundle($this->obs()))->assertOk()->assertJsonPath('data.created', 1);
        $this->withToken($token)->getJson('/api/v1/admin/integrations')->assertOk()->assertJsonPath('data.0.jobs.succeeded', 1);
        $this->withToken($token)->getJson("/api/v1/admin/integrations/{$e->uuid}/reconciliation")->assertOk()->assertJsonPath('data.observations.active', 1);
    }

    public function test_admin_issues_a_scoped_key_once_and_it_works_then_can_be_disabled(): void
    {
        $admin = (new User)->forceFill(['name' => 'a', 'email' => 'admin@example.test', 'password' => self::PASSWORD, 'account_type' => 'admin', 'mfa_enrolled_at' => now()]);
        $admin->save();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->json('data.token');

        $made = $this->withToken($token)->postJson('/api/v1/admin/integrations', ['facility_id' => $this->facility->uuid, 'name' => 'feed', 'protocol' => 'fhir_rest', 'scopes' => ['observations']])->assertStatus(201);
        $key = $made->json('data.api_key');
        $this->assertStringStartsWith('mpk_', $key);
        $this->assertStringNotContainsString($key, json_encode($this->withToken($token)->getJson('/api/v1/admin/integrations')->json()));
        $this->push($this->bundle($this->obs()), 'delivery-0001', $key)->assertOk();

        $this->withToken($token)->postJson("/api/v1/admin/integrations/{$made->json('data.id')}/disable")->assertOk();
        $this->push($this->bundle($this->obs()), 'delivery-0002', $key)->assertStatus(401);
    }

    public function test_rate_limit_applies_to_partner_keys(): void
    {
        $this->endpoint();
        for ($i = 0; $i < 60; $i++) {
            $this->push($this->bundle($this->obs()), 'delivery-same-key')->assertOk();
        }
        $this->push($this->bundle($this->obs()), 'delivery-same-key')->assertStatus(429);
    }

    public function test_fhir_export_is_gated_by_consent_and_carries_provenance(): void
    {
        $this->endpoint();
        $this->push($this->bundle($this->obs()))->assertOk();

        $u = (new User)->forceFill(['name' => 'p', 'email' => 'p@example.test', 'password' => self::PASSWORD, 'account_type' => 'patient']);
        $u->save();
        $this->patient->update(['user_id' => $u->id]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'p@example.test', 'password' => self::PASSWORD])->json('data.token');

        $res = $this->withToken($token)->getJson('/api/v1/patient/fhir')->assertOk();
        $this->assertStringContainsString('application/fhir+json', $res->headers->get('Content-Type'));
        $types = array_column(array_column($res->json('entry'), 'resource'), 'resourceType');
        $this->assertContains('Observation', $types);
        $this->assertContains('Provenance', $types);
        $obs = collect($res->json('entry'))->pluck('resource')->firstWhere('resourceType', 'Observation');
        $this->assertSame('GLU', $obs['code']['coding'][0]['code']);
        $this->assertSame('mmol/L', $obs['valueQuantity']['unit']);

        $this->assertSame(0, Consent::count());
        $this->flushHeaders()->getJson("/api/v1/provider/patients/{$this->patient->uuid}/fhir")->assertStatus(401);
        $this->withToken($token)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/fhir")->assertStatus(403);
    }
}
