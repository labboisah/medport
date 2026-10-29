<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

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
        $pr = Practitioner::create(['user_id' => $u->id, 'given_name' => 'Dr', 'family_name' => 'X', 'verification_status' => 'verified']);
        PractitionerRole::create(['practitioner_id' => $pr->id, 'facility_id' => $f->id, 'role' => 'physician']);

        return [$u, $f];
    }

    private function request(string $token, array $over = [])
    {
        return $this->withToken($token)->postJson('/api/v1/provider/access-requests', $over + ['patient_id' => $this->patient->uuid, 'categories' => ['allergies', 'medications'], 'reason' => 'Clinic visit today', 'duration_hours' => 24]);
    }

    private function allowed(string $token, string $category = 'allergies')
    {
        return $this->withToken($token)->getJson("/api/v1/provider/patients/{$this->patient->uuid}/timeline?categories[]={$category}");
    }

    public function test_request_grants_nothing_until_patient_approves_then_scope_is_enforced(): void
    {
        [$su] = $this->staff('d@example.test');
        $st = $this->token($su);
        $this->request($st)->assertStatus(202);
        $this->allowed($st)->assertStatus(403);

        $pt = $this->token($this->patientUser);
        $list = $this->withToken($pt)->getJson('/api/v1/patient/access-requests')->assertOk();
        $this->assertSame('pending', $list->json('data.0.status'));
        $id = $list->json('data.0.id');

        // Patient limits to allergies only.
        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/approve", ['categories' => ['allergies']])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->allowed($st)->assertOk();
        $this->allowed($st, 'medications')->assertStatus(403);
        $this->assertSame(1, Consent::count());
    }

    public function test_patient_cannot_widen_and_can_deny_and_unknown_patient_looks_identical(): void
    {
        [$su] = $this->staff('d@example.test');
        $st = $this->token($su);
        $this->request($st)->assertStatus(202);
        $id = AccessRequest::first()->uuid;
        $pt = $this->token($this->patientUser);

        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/approve", ['categories' => ['allergies', 'referrals']])->assertStatus(422);
        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/approve", ['duration_hours' => 9999])->assertStatus(422);
        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/deny")->assertOk()->assertJsonPath('data.status', 'denied');
        $this->withToken($pt)->postJson("/api/v1/patient/access-requests/{$id}/approve")->assertStatus(409);
        $this->allowed($st)->assertStatus(403);

        $known = $this->request($st);
        $unknown = $this->request($st, ['patient_id' => (string) Str::uuid()]);
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame(2, AccessRequest::count());
    }

    public function test_other_patient_cannot_decide_and_expired_request_cannot_be_approved(): void
    {
        [$su] = $this->staff('d@example.test');
        $this->request($this->token($su))->assertStatus(202);
        $id = AccessRequest::first()->uuid;
        $u2 = $this->user('patient', 'two@example.test');
        Patient::create(['user_id' => $u2->id, 'given_name' => 'B', 'family_name' => 'B']);

        $this->withToken($this->token($u2))->postJson("/api/v1/patient/access-requests/{$id}/approve")->assertStatus(404);
        $pt = $this->token($this->patientUser);
        $this->travel(73)->hours();
        $this->withToken($this->token($this->patientUser))->postJson("/api/v1/patient/access-requests/{$id}/approve")->assertStatus(409);
        $this->assertSame(0, Consent::count());
    }

    public function test_unverified_provider_cannot_request_access(): void
    {
        [$su] = $this->staff('d@example.test');
        Practitioner::first()->update(['verification_status' => 'pending']);
        $this->request($this->token($su))->assertStatus(403);
        $this->assertSame(0, AccessRequest::count());
    }

    public function test_manual_encounter_is_gated_labelled_and_duplicate_checked(): void
    {
        [$su, $f] = $this->staff('d@example.test');
        $st = $this->token($su);
        $payload = ['encounter_class' => 'outpatient', 'clinical_date' => now()->subDay()->toDateTimeString(), 'reason' => 'Fever'];
        $url = "/api/v1/provider/patients/{$this->patient->uuid}/encounters";

        $this->withToken($st)->postJson($url, $payload)->assertStatus(403); // no consent
        $this->request($st, ['categories' => ['encounters']]);
        $this->withToken($this->token($this->patientUser))->postJson('/api/v1/patient/access-requests/'.AccessRequest::first()->uuid.'/approve')->assertOk();

        $this->withToken($st)->postJson($url, $payload)->assertCreated()->assertJsonPath('data.origin', 'staff_entered');
        $e = Encounter::first();
        $this->assertNull($e->source_system);
        $this->assertSame('unverified', $e->verification_status);
        $this->assertSame('manual_portal_entry', $e->verification_method);

        $this->withToken($st)->postJson($url, $payload)->assertStatus(409)->assertJsonPath('error.code', 'possible_duplicate');
        $this->withToken($st)->postJson($url, $payload + ['confirm_duplicate' => true])->assertCreated();
        $this->withToken($st)->postJson($url, ['clinical_date' => now()->addDay()->toDateTimeString()] + $payload)->assertStatus(422);
        $this->assertSame(2, Encounter::count());
    }

    private function approveFor(string $st, array $cats): void
    {
        $this->request($st, ['categories' => $cats])->assertStatus(202);
        $this->withToken($this->token($this->patientUser))->postJson('/api/v1/patient/access-requests/'.AccessRequest::latest('id')->first()->uuid.'/approve')->assertOk();
    }

    public function test_referral_needs_both_consents_and_outcome_returns_to_timeline(): void
    {
        [$su] = $this->staff('a@example.test');
        [$ru, $dest] = $this->staff('b@example.test');
        $st = $this->token($su);
        $rt = $this->token($ru);
        $url = "/api/v1/provider/patients/{$this->patient->uuid}/referrals";
        $body = ['to_facility_id' => $dest->uuid, 'reason' => 'Cardiology review'];

        $this->withToken($st)->postJson($url, $body)->assertStatus(403); // sender lacks consent
        $this->approveFor($st, ['referrals']);
        $this->withToken($st)->postJson($url, $body)->assertStatus(422); // destination lacks consent
        $this->assertSame(0, Referral::count());

        $this->approveFor($rt, ['referrals']);
        $ref = $this->withToken($st)->postJson($url, $body)->assertCreated()->json('data.id');

        $incoming = $this->withToken($rt)->getJson('/api/v1/provider/referrals/incoming')->assertOk();
        $this->assertSame('Cardiology review', $incoming->json('data.0.reason'));
        $this->withToken($st)->getJson('/api/v1/provider/referrals/incoming')->assertJsonCount(0, 'data');

        $dec = "/api/v1/provider/referrals/{$ref}/decision";
        $this->withToken($st)->postJson($dec, ['status' => 'accepted'])->assertStatus(404); // sender is not the destination
        $this->withToken($rt)->postJson($dec, ['status' => 'completed', 'outcome' => 'x'])->assertStatus(409);
        $this->withToken($rt)->postJson($dec, ['status' => 'accepted'])->assertOk();
        $this->withToken($rt)->postJson($dec, ['status' => 'completed'])->assertStatus(422);
        $this->withToken($rt)->postJson($dec, ['status' => 'completed', 'outcome' => 'Seen; follow-up in 6 weeks'])->assertOk();

        $tl = $this->withToken($this->token($this->patientUser))->getJson('/api/v1/patient/timeline?categories[]=referrals')->assertOk();
        $this->assertSame('completed', $tl->json('data.0.referral_status'));
        $this->assertSame('Seen; follow-up in 6 weeks', $tl->json('data.0.outcome_text'));
        $this->assertGreaterThanOrEqual(3, AuditEvent::where('resource_type', 'referral')->count());
    }

    public function test_revoked_destination_consent_hides_referral(): void
    {
        [$su] = $this->staff('a@example.test');
        [$ru, $dest] = $this->staff('b@example.test');
        $st = $this->token($su);
        $rt = $this->token($ru);
        $this->approveFor($st, ['referrals']);
        $this->approveFor($rt, ['referrals']);
        $ref = $this->withToken($st)->postJson("/api/v1/provider/patients/{$this->patient->uuid}/referrals", ['to_facility_id' => $dest->uuid, 'reason' => 'Cardiology review'])->json('data.id');

        $consent = Consent::where('recipient_facility_id', $dest->id)->first();
        $this->withToken($this->token($this->patientUser))->postJson("/api/v1/patient/consents/{$consent->uuid}/revoke")->assertOk();

        $this->withToken($rt)->getJson('/api/v1/provider/referrals/incoming')->assertJsonCount(0, 'data');
        $this->withToken($rt)->postJson("/api/v1/provider/referrals/{$ref}/decision", ['status' => 'accepted'])->assertStatus(404);
    }

    public function test_patient_role_cannot_use_provider_workflows(): void
    {
        $pt = $this->token($this->patientUser);
        $this->withToken($pt)->getJson('/api/v1/provider/referrals/incoming')->assertStatus(403);
        $this->request($pt)->assertStatus(403);
    }
}
