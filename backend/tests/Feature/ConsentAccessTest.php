<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\ShareToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    // The auth guard caches the resolved user inside one test process; reset it so each request authenticates on its own token.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
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

    private function patient(string $email = 'pat@example.test'): array
    {
        $u = $this->user('patient', $email);
        $p = Patient::create(['user_id' => $u->id, 'given_name' => 'Amina', 'family_name' => 'Test', 'birth_date' => '1990-04-02']);

        return [$u, $p];
    }

    private function staff(string $email, ?Facility $facility = null): array
    {
        $u = $this->user('practitioner', $email);
        $f = $facility ?? Facility::create(['name' => 'Clinic '.$email, 'type' => 'clinic', 'verification_status' => 'verified']);
        $pr = Practitioner::create(['user_id' => $u->id, 'given_name' => 'D', 'family_name' => 'D', 'verification_status' => 'verified']);
        PractitionerRole::create(['practitioner_id' => $pr->id, 'facility_id' => $f->id, 'role' => 'physician']);

        return [$u, $f, $pr];
    }

    private function share(string $patientToken, Facility $f, array $cats = ['allergies'], int $hours = 24)
    {
        return $this->withToken($patientToken)->postJson('/api/v1/patient/consents', ['facility_id' => $f->uuid, 'categories' => $cats, 'duration_hours' => $hours]);
    }

    private function check(string $token, Patient $p, string $cat = 'allergies', string $purpose = 'direct_care')
    {
        return $this->withToken($token)->postJson('/api/v1/access/check', ['patient_id' => $p->uuid, 'category' => $cat, 'purpose' => $purpose]);
    }

    public function test_no_consent_means_denied_and_audited(): void
    {
        [, $p] = $this->patient();
        [$su] = $this->staff('d@example.test');

        $this->check($this->token($su), $p)->assertStatus(403)->assertExactJson(['data' => ['allowed' => false]]);
        $this->assertSame(1, AuditEvent::where('action', 'read')->where('outcome', 'denied')->where('authorization_basis', 'no_effective_grant')->count());
    }

    public function test_consent_allows_only_shared_category_facility_and_purpose(): void
    {
        [$pu, $p] = $this->patient();
        [$su, $f] = $this->staff('d@example.test');
        [$ou] = $this->staff('other@example.test');
        $this->share($this->token($pu), $f, ['allergies'])->assertCreated();

        $t = $this->token($su);
        $this->check($t, $p)->assertOk()->assertJsonPath('data.allowed', true);
        $this->check($t, $p, 'medications')->assertStatus(403);
        $this->check($t, $p, 'allergies', 'research')->assertStatus(403);
        $this->check($this->token($ou), $p)->assertStatus(403); // wrong facility
        $this->assertSame(1, AuditEvent::where('outcome', 'success')->where('action', 'read')->count());
    }

    public function test_revocation_and_expiry_fail_closed(): void
    {
        [$pu, $p] = $this->patient();
        [$su, $f] = $this->staff('d@example.test');
        $pt = $this->token($pu);
        $id = $this->share($pt, $f)->json('data.id');
        $st = $this->token($su);
        $this->check($st, $p)->assertOk();

        $this->withToken($pt)->postJson("/api/v1/patient/consents/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->check($st, $p)->assertStatus(403);

        $this->share($pt, $f, ['allergies'], 1)->assertCreated();
        $this->check($st, $p)->assertOk();
        $this->travel(2)->hours();
        $this->assertSame(401, $this->check($st, $p)->status()); // token also expired
        $this->travelBack();
        $this->travel(61)->minutes();
        $st2 = $this->token($su);
        $this->check($st2, $p)->assertStatus(403);
    }

    public function test_unverified_facility_or_suspended_role_or_practitioner_is_denied(): void
    {
        [$pu, $p] = $this->patient();
        [$su, $f, $pr] = $this->staff('d@example.test');
        $this->share($this->token($pu), $f)->assertCreated();
        $st = $this->token($su);
        $this->check($st, $p)->assertOk();

        $pr->roles()->update(['status' => 'suspended']);
        $this->check($st, $p)->assertStatus(403);
        $pr->roles()->update(['status' => 'active']);
        $f->update(['verification_status' => 'suspended']);
        $this->check($st, $p)->assertStatus(403);
        $f->update(['verification_status' => 'verified']);
        $pr->update(['verification_status' => 'suspended']);
        $this->check($st, $p)->assertStatus(403);
    }

    public function test_patient_cannot_share_with_unverified_facility_or_exceed_limits_or_revoke_others(): void
    {
        [$pu] = $this->patient();
        [$qu] = $this->patient('b@example.test');
        $pending = Facility::create(['name' => 'Pending', 'type' => 'clinic']);
        $ok = Facility::create(['name' => 'Ok', 'type' => 'clinic', 'verification_status' => 'verified']);
        $pt = $this->token($pu);

        $this->share($pt, $pending)->assertStatus(422);
        $this->share($pt, $ok, ['allergies'], 100000)->assertStatus(422);
        $this->share($pt, $ok, ['secret_notes'])->assertStatus(422);
        $this->share($pt, $ok, [])->assertStatus(422);

        $id = $this->share($pt, $ok)->json('data.id');
        $this->withToken($this->token($qu))->postJson("/api/v1/patient/consents/{$id}/revoke")->assertStatus(404);
        $this->assertSame('active', Consent::first()->status);
        $this->withToken($this->token($qu))->getJson('/api/v1/patient/consents')->assertJsonCount(0, 'data');
    }

    public function test_role_boundaries_on_new_endpoints(): void
    {
        [$pu, $p] = $this->patient();
        [$su] = $this->staff('d@example.test');
        $this->withToken($this->token($su))->getJson('/api/v1/patient/consents')->assertStatus(403);
        $this->withToken($this->token($pu))->postJson('/api/v1/access/check', ['patient_id' => $p->uuid, 'category' => 'allergies', 'purpose' => 'direct_care'])->assertStatus(403);
        $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/access/check', [])->assertStatus(401);
    }

    public function test_unknown_patient_looks_the_same_as_no_consent(): void
    {
        [, $p] = $this->patient();
        [$su] = $this->staff('d@example.test');
        $t = $this->token($su);
        $known = $this->check($t, $p);
        $unknown = $this->withToken($t)->postJson('/api/v1/access/check', ['patient_id' => (string) Str::uuid(), 'category' => 'allergies', 'purpose' => 'direct_care']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_qr_token_is_opaque_short_lived_hashed_and_grants_nothing(): void
    {
        [$pu, $p] = $this->patient();
        [$su] = $this->staff('d@example.test');
        $plain = $this->withToken($this->token($pu))->postJson('/api/v1/patient/share-tokens')->assertCreated()->json('data.token');

        $this->assertNull(ShareToken::where('token_hash', $plain)->first());
        $this->assertStringNotContainsString($p->uuid, $plain);

        $st = $this->token($su);
        $res = $this->withToken($st)->postJson('/api/v1/share-tokens/resolve', ['token' => $plain])->assertOk();
        $this->assertSame(['patient_id', 'access'], array_keys($res->json('data')));
        $this->assertSame('consent_required', $res->json('data.access'));
        $this->check($st, $p)->assertStatus(403); // scanning is not authorization

        $this->withToken($st)->postJson('/api/v1/share-tokens/resolve', ['token' => 'bogus'])->assertStatus(404);
        $this->travel(11)->minutes();
        $st = $this->token($su);
        $this->withToken($st)->postJson('/api/v1/share-tokens/resolve', ['token' => $plain])->assertStatus(404);
    }

    public function test_audit_has_no_payload_is_append_only_and_patient_sees_history(): void
    {
        [$pu, $p] = $this->patient();
        [$su, $f] = $this->staff('d@example.test');
        $pt = $this->token($pu);
        $this->share($pt, $f)->assertCreated();
        $this->check($this->token($su), $p)->assertOk();

        $history = $this->withToken($pt)->getJson('/api/v1/patient/access-history')->assertOk();
        $actions = collect($history->json('data'))->pluck('action')->all();
        $this->assertContains('share', $actions);
        $this->assertContains('read', $actions);
        $this->assertArrayNotHasKey('actor_id', $history->json('data.0'));

        $this->expectException(\LogicException::class);
        AuditEvent::first()->update(['outcome' => 'tampered']);
    }

    public function test_failed_audit_write_fails_closed(): void
    {
        [$pu, $p] = $this->patient();
        [$su, $f] = $this->staff('d@example.test');
        $this->share($this->token($pu), $f)->assertCreated();
        $t = $this->token($su);

        AuditEvent::creating(fn () => throw new \RuntimeException('audit store down'));
        $res = $this->check($t, $p);

        $this->assertSame(500, $res->status());
        $this->assertNull($res->json('data'));
    }
}
