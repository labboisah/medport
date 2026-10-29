<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientMatchCandidate;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    private const MAX_FAILURES = 5;

    private const LOCK_MINUTES = 15;

    /** Self-registration creates patient accounts only; staff accounts are provisioned by an admin. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'given_name' => ['required', 'string', 'max:100'],
            'family_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:tomorrow'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ]);

        // Identical response whether or not the email exists, so registration cannot enumerate accounts.
        $accepted = response()->json(['data' => ['status' => 'registration_received']], 202);
        if (User::where('email', $data['email'])->exists()) {
            Audit::record($request, 'account', 'register', 'denied');

            return $accepted;
        }

        DB::transaction(function () use ($data, $request) {
            $user = (new User)->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'account_type' => 'patient',
            ]);
            $user->save();

            $patient = Patient::create([
                'user_id' => $user->id,
                'given_name' => $data['given_name'],
                'family_name' => $data['family_name'],
                'birth_date' => $data['birth_date'],
            ]);

            // Possible duplicates go to human review; they are never linked or merged automatically.
            Patient::where('id', '!=', $patient->id)
                ->where('status', 'active')
                ->whereRaw('lower(family_name) = ?', [mb_strtolower($data['family_name'])])
                ->whereRaw('lower(given_name) = ?', [mb_strtolower($data['given_name'])])
                ->whereDate('birth_date', $data['birth_date'])
                ->each(fn (Patient $other) => PatientMatchCandidate::create([
                    'patient_id' => $patient->id,
                    'candidate_patient_id' => $other->id,
                    'score' => 0, // placeholder: scoring thresholds await the clinical owner's policy
                    'reasons' => ['name_and_birth_date_equal'],
                ]));

            Audit::record($request, 'account', 'register', 'success', $user, ['patient_id' => $patient->id]);
        });

        return $accepted;
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = User::where('email', $data['email'])->first();
        $locked = $user?->locked_until?->isFuture() ?? false;
        // Always run a hash check so timing does not reveal whether the account exists.
        $valid = Hash::check($data['password'], $user->password ?? Hash::make('timing-equalizer'));

        if (! $user || $locked || ! $valid || ! $user->isActive()) {
            if ($user && ! $locked && ! $valid) {
                $user->failed_login_count++;
                if ($user->failed_login_count >= self::MAX_FAILURES) {
                    $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
                    $user->failed_login_count = 0;
                }
                $user->save();
            }
            Audit::record($request, 'session', 'login', 'denied', $user);
            throw new HttpException(401, 'Invalid credentials.');
        }

        // Fail closed: staff and privileged accounts cannot sign in until MFA is enrolled.
        if ($user->isStaff() && ! $user->mfa_enrolled_at) {
            Audit::record($request, 'session', 'login', 'denied', $user);
            throw new HttpException(403, 'Multi-factor authentication is required.');
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        $token = $user->createToken('api', ['*'], now()->addMinutes((int) config('sanctum.expiration')));
        Audit::record($request, 'session', 'login', 'success', $user);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'account_type' => $user->account_type,
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        Audit::record($request, 'session', 'logout', 'success', $request->user());

        return response()->json(['data' => ['status' => 'logged_out']]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => $user->account_type,
            'identity_verification' => $user->patient?->identity_verification,
        ]]);
    }
}
