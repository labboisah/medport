<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Patient;
use App\Models\PractitionerRole;
use App\Models\User;

/**
 * Single server-side access decision. Every branch fails closed: anything not explicitly allowed is denied.
 */
class AccessDecision
{
    public const CATEGORIES = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports', 'documents', 'referrals'];

    public const PURPOSES = ['direct_care'];

    /** @return array{allowed: bool, reason: string, facility_id: ?int, grant_id: ?int} */
    public static function decide(User $user, Patient $patient, string $category, string $purpose): array
    {
        $deny = fn (string $reason, ?int $facility = null) => ['allowed' => false, 'reason' => $reason, 'facility_id' => $facility, 'grant_id' => null];

        $practitioner = $user->practitioner;
        if (! $user->isActive() || $user->account_type !== 'practitioner' || ! $user->mfa_enrolled_at) {
            return $deny('not_a_verified_practitioner_account');
        }
        if ($practitioner?->verification_status !== 'verified') {
            return $deny('practitioner_not_verified');
        }
        if ($patient->status !== 'active') {
            return $deny('patient_not_active');
        }
        if (! in_array($category, self::CATEGORIES, true) || ! in_array($purpose, self::PURPOSES, true)) {
            return $deny('category_or_purpose_not_recognised');
        }

        $roles = PractitionerRole::where('practitioner_id', $practitioner->id)->where('status', 'active')
            ->whereHas('facility', fn ($q) => $q->where('verification_status', 'verified'))->get();
        if ($roles->isEmpty()) {
            return $deny('no_active_role_at_verified_facility');
        }

        $grants = AccessGrant::effective()->where('patient_id', $patient->id)->where('type', 'consent')
            ->whereIn('facility_id', $roles->pluck('facility_id'))
            ->where(fn ($q) => $q->whereNull('practitioner_id')->orWhere('practitioner_id', $practitioner->id))
            ->with('consent')->get();

        foreach ($grants as $grant) {
            $consent = $grant->consent;
            if (! $consent || $consent->status !== 'active' || $consent->revoked_at || $consent->expires_at->isPast()) {
                continue;
            }
            if ($consent->purpose !== $purpose || ! in_array($category, $consent->categories, true) || ! in_array($category, $grant->scope, true)) {
                continue;
            }

            return ['allowed' => true, 'reason' => 'active_consent', 'facility_id' => $grant->facility_id, 'grant_id' => $grant->id];
        }

        return $deny($grants->isEmpty() ? 'no_effective_grant' : 'out_of_scope', $roles->first()->facility_id);
    }
}
