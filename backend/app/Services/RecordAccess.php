<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Http\Request;

/** Gate for clinical reads: decide, then audit before anything is returned. A failed audit write aborts the request. */
class RecordAccess
{
    /** @return array{allowed: bool, facility_id: ?int} */
    public static function authorize(Request $request, Patient $patient, string $category, string $purpose = 'direct_care'): array
    {
        $user = $request->user();

        if ($user->account_type === 'patient' && $user->patient?->id === $patient->id) {
            $decision = ['allowed' => true, 'reason' => 'patient_self', 'facility_id' => null, 'grant_id' => null];
        } else {
            $decision = AccessDecision::decide($user, $patient, $category, $purpose);
        }

        Audit::record($request, 'patient_record', 'read', $decision['allowed'] ? 'success' : 'denied', $user, [
            'patient_id' => $patient->id,
            'facility_id' => $decision['facility_id'],
            'resource_id' => $category,
            'purpose' => $purpose,
            'authorization_basis' => $decision['reason'],
        ]);

        if ($decision['allowed'] && $decision['facility_id']) {
            // At most one notice per patient and facility per hour, so reading a timeline does not flood the inbox.
            Notifier::send($patient->user_id, 'access_event', (string) $decision['facility_id'], 60);
        }

        return ['allowed' => $decision['allowed'], 'facility_id' => $decision['facility_id']];
    }
}
