<?php

namespace App\Services;

use App\Models\PractitionerRole;
use App\Models\User;

class StaffContext
{
    /** First active role at a verified facility for a verified practitioner, or null. */
    public static function role(User $user): ?PractitionerRole
    {
        $p = $user->practitioner;
        if (! $user->isActive() || $user->account_type !== 'practitioner' || ! $user->mfa_enrolled_at || $p?->verification_status !== 'verified') {
            return null;
        }

        return PractitionerRole::where('practitioner_id', $p->id)->where('status', 'active')
            ->whereHas('facility', fn ($q) => $q->where('verification_status', 'verified'))->first();
    }
}
