<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PatientIdentifier;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientSearchController extends Controller
{
    /**
     * Exact lookup by an approved identifier only (no name or fuzzy search), minimum-necessary result,
     * available to verified practitioners holding an active role at a verified facility.
     * Every outcome returns the same shape, so callers cannot probe for who exists.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'system' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $practitioner = $user->practitioner;
        $role = $practitioner?->verification_status === 'verified'
            ? $practitioner->roles()->where('status', 'active')
                ->whereHas('facility', fn ($q) => $q->where('verification_status', 'verified'))
                ->first()
            : null;

        if (! $role) {
            Audit::record($request, 'patient', 'search', 'denied', $user);

            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden.', 'correlation_id' => $request->attributes->get('correlation_id')]], 403);
        }

        $patient = PatientIdentifier::where('system', $data['system'])->where('value', $data['value'])
            ->first()?->patient;
        if ($patient && $patient->status !== 'active') {
            $patient = null;
        }

        Audit::record($request, 'patient', 'search', 'success', $user, ['facility_id' => $role->facility_id, 'patient_id' => $patient?->id]);

        return response()->json(['data' => $patient ? [[
            'id' => $patient->uuid,
            'given_name' => $patient->given_name,
            'family_name' => $patient->family_name,
            'birth_year' => $patient->birth_date?->format('Y'),
            'identity_verification' => $patient->identity_verification,
        ]] : []]);
    }
}
