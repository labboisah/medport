<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\AccessRequest;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\InboxNotification;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private Patient $patient;

    private User $patientUser;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
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

    private function staff(string $email): array
    {
        $u = $this->user('practitioner', $email);
        $f = Facility::create(['name' => 'Secret Clinic', 'type' => 'clinic', 'verification_status' => 'verified']);
        $pr = Practitioner::create(['user_id' => $u->id, 'given_name' => 'Dr', 'family_name' => 'X', 'verification_status' => 'verified']);
        PractitionerRole::create(['practitioner_id' => $pr->id, 'facility_id' => $f->id, 'role' => 'physician']);

        return [$u, $f];
    }

    private function admin(): string
    {
        return $this->token($this->user('admin', 'admin@example.test'));
    }

    public function test_notifications_are_minimal_respect_preferences_and_security_notices_are_mandatory(): void
    {
        [$su] = $this->staff('d@example.test');
        $st = $this->token($su);
        $pt = $this->token($this->patientUser);

        $this->withToken($st)->postJson('/api/v1/provider/access-requests', ['patient_id' => $this->patient->uuid, 'categories' => ['allergies'], 'reason' => 'Chest pain follow up', 'duration_hours' => 24])->assertStatus(202);
        $list = $this->withToken($pt)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 1);
        $text = json_encode($list->json());
        foreach (['Secret Clinic', 'Chest pain', 'allergies', 'Dr X'] as $leak) {
            $this->assertStringNotContainsString($leak, $text);
        }

        $this->withToken($pt)->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);

        $this->withToken($pt)->putJson('/api/v1/notifications/preferences', ['event' => 'access_event', 'in_app' => false])->assertOk();
        $this->withToken($pt)->putJson('/api/v1/notifications/preferences', ['event' => 'security_change', 'in_app' => false])->assertStatus(422);
        $this->withToken($pt)->putJson('/api/v1/notifications/preferences', ['event' => 'nope', 'in_app' => false])->assertStatus(422);

        $id = AccessRequest::first()->uuid;
        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/approve")->assertOk();
        $this->assertSame(1, InboxNotification::where('user_id', $su->id)->where('event', 'access_decided')->count());

        // Opted out of access-event notices, so viewing records adds none.
        $this->withToken($st)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline?categories[]=allergies")->assertOk();
        $this->assertSame(0, InboxNotification::where('user_id', $this->patientUser->id)->where('event', 'access_event')->count());
    }

    public function test_access_notices_are_rate_limited_and_other_users_cannot_read_them(): void
    {
        [$su] = $this->staff('d@example.test');
        $st = $this->token($su);
        $pt = $this->token($this->patientUser);
        $this->withToken($st)->postJson('/api/v1/provider/access-requests', ['patient_id' => $this->patient->uuid, 'categories' => ['allergies'], 'reason' => 'Clinic visit today', 'duration_hours' => 24]);
        $this->withToken($pt)->postJson('/api/v1/patient/access-requests/'.AccessRequest::first()->uuid.'/approve')->assertOk();

        foreach (range(1, 3) as $_) {
            $this->withToken($st)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline?categories[]=allergies")->assertOk();
        }
        $this->assertSame(1, InboxNotification::where('user_id', $this->patientUser->id)->where('event', 'access_event')->count());
        $this->withToken($st)->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['event' => 'access_event']);
    }

    public function test_the_delivery_job_is_idempotent_and_skips_disabled_accounts(): void
    {
        (new DeliverNotification($this->patientUser->id, 'access_requested', 'k1'))->handle();
        (new DeliverNotification($this->patientUser->id, 'access_requested', 'k1'))->handle();
        $this->assertSame(1, InboxNotification::count());

        $this->patientUser->forceFill(['status' => 'disabled'])->save();
        (new DeliverNotification($this->patientUser->id, 'access_requested', 'k2'))->handle();
        $this->assertSame(1, InboxNotification::count());
    }

    public function test_failed_queue_work_is_visible_without_payloads_and_can_be_retried(): void
    {
        config(['queue.default' => 'database']);
        DeliverNotification::dispatch($this->patientUser->id, 'access_requested', 'k9');
        $row = DB::table('jobs')->first();
        $uuid = json_decode($row->payload, true)['uuid'];
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => $row->queue, 'payload' => $row->payload, 'exception' => "RuntimeException: secret patient name in message\n#0 trace", 'failed_at' => now()]);
        DB::table('jobs')->delete();
        $admin = $this->admin();

        $metrics = $this->withToken($admin)->getJson('/api/v1/admin/ops/metrics')->assertOk()->assertJsonPath('data.queue.failed', 1);
        $failed = $this->withToken($admin)->getJson('/api/v1/admin/ops/failed-jobs')->assertOk()->assertJsonPath('data.0.job', 'DeliverNotification')->assertJsonPath('data.0.exception', 'RuntimeException');
        $this->assertStringNotContainsString('secret', json_encode($failed->json()));
        $this->assertStringNotContainsString('k9', json_encode($failed->json()));

        $this->withToken($admin)->postJson("/api/v1/admin/ops/failed-jobs/{$uuid}/retry")->assertOk();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertTrue(AuditEvent::where('action', 'retry')->where('resource_type', 'queue_job')->exists());
        $this->withToken($admin)->postJson('/api/v1/admin/ops/failed-jobs/'.Str::uuid().'/retry')->assertStatus(404);
        $this->assertArrayHasKey('lag_seconds', $metrics->json('data.queue'));
    }

    public function test_metrics_count_failed_logins_and_are_admin_only(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->patientUser->email, 'password' => 'wrong-password-x']);
        $admin = $this->admin();
        $this->withToken($admin)->getJson('/api/v1/admin/ops/metrics')->assertOk()->assertJsonPath('data.security_24h.failed_logins', 1);
        $this->withToken($this->token($this->patientUser))->getJson('/api/v1/admin/ops/metrics')->assertStatus(403);
        $this->flushHeaders()->getJson('/api/v1/admin/ops/metrics')->assertStatus(401);
    }

    public function test_incidents_policy_and_audit_review_are_admin_controlled_and_audited(): void
    {
        $admin = $this->admin();
        $made = $this->withToken($admin)->postJson('/api/v1/admin/incidents', ['category' => 'partner_outage', 'severity' => 'medium', 'title' => 'Lab feed down'])->assertStatus(201);
        $this->withToken($admin)->postJson('/api/v1/admin/incidents', ['category' => 'bogus', 'severity' => 'medium', 'title' => 'x'])->assertStatus(422);
        $this->withToken($admin)->patchJson('/api/v1/admin/incidents/'.$made->json('data.id'), ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->withToken($admin)->getJson('/api/v1/admin/ops/metrics')->assertJsonPath('data.open_incidents', 0);

        $this->withToken($admin)->putJson('/api/v1/admin/policy/consent_max_hours', ['value' => 99999])->assertStatus(422);
        $this->withToken($admin)->putJson('/api/v1/admin/policy/unknown_key', ['value' => 5])->assertStatus(404);
        $this->withToken($admin)->putJson('/api/v1/admin/policy/consent_max_hours', ['value' => 48])->assertOk();
        $this->assertSame(48, config('medipass.consent_max_hours'));
        $this->assertDatabaseHas('policy_settings', ['key' => 'consent_max_hours', 'value' => 48]);

        $this->withToken($this->token($this->patientUser))->postJson('/api/v1/patient/consents', ['facility_id' => Facility::create(['name' => 'F', 'type' => 'clinic', 'verification_status' => 'verified'])->uuid, 'categories' => ['allergies'], 'duration_hours' => 100])->assertStatus(422);

        $audit = $this->withToken($admin)->getJson('/api/v1/admin/audit?resource_type=policy_setting')->assertOk();
        $this->assertSame('update', $audit->json('data.0.action'));
        $this->assertArrayNotHasKey('patient_id', $audit->json('data.0'));
        $this->assertSame(0, Consent::count());
    }

    public function test_role_changes_send_a_mandatory_security_notice(): void
    {
        [$su, $f] = $this->staff('d@example.test');
        $admin = $this->admin();
        $role = PractitionerRole::first();
        $this->withToken($admin)->postJson("/api/v1/admin/practitioner-roles/{$role->id}/suspend")->assertOk();
        $this->assertSame(1, InboxNotification::where('user_id', $su->id)->where('event', 'security_change')->count());
    }

    public function test_pending_verification_lists_unverified_only(): void
    {
        Facility::create(['name' => 'Pending Clinic', 'type' => 'clinic', 'verification_status' => 'pending']);
        Facility::create(['name' => 'Done Clinic', 'type' => 'clinic', 'verification_status' => 'verified']);
        $res = $this->withToken($this->admin())->getJson('/api/v1/admin/pending-verification')->assertOk();
        $this->assertSame(['Pending Clinic'], array_column($res->json('data.facilities'), 'name'));
    }
}
