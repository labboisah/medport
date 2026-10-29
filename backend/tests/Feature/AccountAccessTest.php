<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\PatientMatchCandidate;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private function account(string $type, bool $mfa = true, ?string $email = null): User
    {
        $u = (new User)->forceFill([
            'name' => 'Test '.$type, 'email' => $email ?? $type.'@example.test', 'password' => self::PASSWORD,
            'account_type' => $type, 'mfa_enrolled_at' => $mfa ? now() : null,
        ]);
        $u->save();

        return $u;
    }

    private function login(User $u): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->json('data.token');
    }

    private function staffAt(string $facilityStatus = 'verified', string $practitionerStatus = 'verified'): User
    {
        $user = $this->account('practitioner');
        $f = Facility::create(['name' => 'Synthetic Clinic', 'type' => 'clinic', 'verification_status' => $facilityStatus]);
        $p = Practitioner::create(['user_id' => $user->id, 'given_name' => 'Syn', 'family_name' => 'Doc', 'verification_status' => $practitionerStatus]);
        PractitionerRole::create(['practitioner_id' => $p->id, 'facility_id' => $f->id, 'role' => 'physician']);

        return $user;
    }

    private function patientWithId(): Patient
    {
        $p = Patient::create(['given_name' => 'Amina', 'family_name' => 'Test', 'birth_date' => '1990-04-02']);
        PatientIdentifier::create(['patient_id' => $p->id, 'system' => 'synthetic_id', 'value' => 'SYN-1']);

        return $p;
    }

    public function test_registration_creates_unverified_patient_and_never_reveals_existing_email(): void
    {
        $payload = ['name' => 'A T', 'given_name' => 'Amina', 'family_name' => 'Test', 'birth_date' => '1990-04-02', 'email' => 'a@example.test', 'password' => self::PASSWORD];
        $first = $this->postJson('/api/v1/auth/register', $payload);
        $second = $this->postJson('/api/v1/auth/register', $payload);

        $first->assertStatus(202);
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, User::count());
        $this->assertSame('unverified', Patient::first()->identity_verification);
        $this->assertSame('patient', User::first()->account_type);
    }

    public function test_registration_cannot_self_assign_staff_type(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'given_name' => 'X', 'family_name' => 'Y', 'birth_date' => '1990-01-01', 'email' => 'x@example.test', 'password' => self::PASSWORD, 'account_type' => 'admin']);

        $this->assertSame('patient', User::first()->account_type);
    }

    public function test_possible_duplicate_goes_to_review_and_is_not_linked(): void
    {
        $existing = $this->patientWithId();
        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'given_name' => 'amina', 'family_name' => 'TEST', 'birth_date' => '1990-04-02', 'email' => 'dup@example.test', 'password' => self::PASSWORD])->assertStatus(202);

        $this->assertSame(1, PatientMatchCandidate::where('status', 'pending_review')->count());
        $this->assertSame(2, Patient::count());
        $this->assertSame('active', $existing->fresh()->status);
        $this->assertNull($existing->fresh()->merged_into_patient_id);
    }

    public function test_login_errors_are_generic_and_lock_after_repeated_failures(): void
    {
        $u = $this->account('patient');
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-password']);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong-password-1']);
        $this->assertSame(401, $unknown->status());
        $this->assertSame($unknown->json('error.message'), $wrong->json('error.message'));

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong-password-1']);
        }
        $this->assertNotNull($u->fresh()->locked_until);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertStatus(401);
    }

    public function test_staff_cannot_sign_in_without_mfa(): void
    {
        $u = $this->account('practitioner', mfa: false);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertStatus(403);
    }

    public function test_logout_revokes_token_and_disabled_users_lose_access(): void
    {
        $u = $this->account('patient');
        $token = $this->login($u);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $u->tokens()->count());

        $token = $this->login($u);
        $u->forceFill(['status' => 'disabled'])->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $u = $this->account('patient');
        $token = $this->login($u);
        $this->travel(2)->hours();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_unauthenticated_and_wrong_role_are_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        $patientToken = $this->login($this->account('patient'));
        $this->withToken($patientToken)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'SYN-1'])->assertStatus(403);
        $this->withToken($patientToken)->postJson('/api/v1/admin/users/1/disable')->assertStatus(403);
    }

    public function test_verified_practitioner_gets_minimum_necessary_exact_match_and_is_audited(): void
    {
        $this->patientWithId();
        $token = $this->login($this->staffAt());

        $res = $this->withToken($token)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'SYN-1'])->assertOk();
        $this->assertSame(['id', 'given_name', 'family_name', 'birth_year', 'identity_verification'], array_keys($res->json('data.0')));
        $this->assertSame('1990', $res->json('data.0.birth_year'));

        $miss = $this->withToken($token)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'NOPE'])->assertOk();
        $this->assertSame([], $miss->json('data'));
        $this->assertSame(2, AuditEvent::where('action', 'search')->where('outcome', 'success')->count());

        $this->withToken($token)->postJson('/api/v1/patients/search', ['family_name' => 'Test'])->assertStatus(422);
    }

    public function test_unverified_practitioner_or_facility_cannot_search(): void
    {
        $this->patientWithId();
        $a = $this->login($this->staffAt(practitionerStatus: 'pending'));
        $this->withToken($a)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'SYN-1'])->assertStatus(403);

        $u = $this->account('practitioner', email: 'b@example.test');
        $f = Facility::create(['name' => 'Pending Clinic', 'type' => 'clinic']);
        $p = Practitioner::create(['user_id' => $u->id, 'given_name' => 'B', 'family_name' => 'B', 'verification_status' => 'verified']);
        PractitionerRole::create(['practitioner_id' => $p->id, 'facility_id' => $f->id, 'role' => 'nurse']);
        $b = $this->login($u);
        $this->withToken($b)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'SYN-1'])->assertStatus(403);
        $this->assertSame(2, AuditEvent::where('action', 'search')->where('outcome', 'denied')->count());
    }

    public function test_suspended_role_removes_search_access(): void
    {
        $this->patientWithId();
        $staff = $this->staffAt();
        $token = $this->login($staff);
        $staff->practitioner->roles()->update(['status' => 'suspended']);

        $this->withToken($token)->postJson('/api/v1/patients/search', ['system' => 'synthetic_id', 'value' => 'SYN-1'])->assertStatus(403);
    }

    public function test_admin_can_verify_assign_and_disable(): void
    {
        $token = $this->login($this->account('admin'));
        $f = Facility::create(['name' => 'New Clinic', 'type' => 'clinic']);
        $target = $this->account('practitioner', email: 'p@example.test');
        $p = Practitioner::create(['user_id' => $target->id, 'given_name' => 'P', 'family_name' => 'P']);

        $this->withToken($token)->postJson("/api/v1/admin/facilities/{$f->uuid}/verify")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/practitioners/{$p->uuid}/verify")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/practitioners/{$p->uuid}/roles", ['facility_id' => $f->uuid, 'role' => 'physician'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/admin/practitioners/{$p->uuid}/roles", ['facility_id' => $f->uuid, 'role' => 'superuser'])->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/admin/users/{$target->id}/disable")->assertOk();

        $this->assertSame('verified', $f->fresh()->verification_status);
        $this->assertSame('verified', $p->fresh()->verification_status);
        $this->assertSame('disabled', $target->fresh()->status);
        $this->assertSame(1, AuditEvent::where('action', 'disable')->count());
    }
}
