<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\Practitioner;
use App\Models\PractitionerRole;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform-admin verification of facilities and practitioners, role assignment and account disabling. */
class AdminController extends Controller
{
    public const ROLES = ['physician', 'nurse', 'lab_scientist', 'pharmacist', 'records_officer', 'facility_admin'];

    public function verifyFacility(Request $request, Facility $facility): JsonResponse
    {
        $facility->update(['verification_status' => 'verified', 'verified_at' => now()]);
        Audit::record($request, 'facility', 'verify', 'success', $request->user(), ['facility_id' => $facility->id, 'resource_id' => $facility->uuid]);

        return response()->json(['data' => ['status' => 'verified']]);
    }

    public function verifyPractitioner(Request $request, Practitioner $practitioner): JsonResponse
    {
        $practitioner->update(['verification_status' => 'verified', 'verified_at' => now()]);
        Audit::record($request, 'practitioner', 'verify', 'success', $request->user(), ['resource_id' => $practitioner->uuid]);

        return response()->json(['data' => ['status' => 'verified']]);
    }

    public function assignRole(Request $request, Practitioner $practitioner): JsonResponse
    {
        $data = $request->validate([
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'uuid')],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        $facility = Facility::where('uuid', $data['facility_id'])->firstOrFail();

        PractitionerRole::updateOrCreate(
            ['practitioner_id' => $practitioner->id, 'facility_id' => $facility->id, 'role' => $data['role']],
            ['status' => 'active'],
        );
        Audit::record($request, 'practitioner_role', 'assign', 'success', $request->user(), ['facility_id' => $facility->id, 'resource_id' => $practitioner->uuid]);
        Notifier::send($practitioner->user_id, 'security_change', 'role-'.$practitioner->uuid.'-'.now()->timestamp);

        return response()->json(['data' => ['status' => 'active']], 201);
    }

    public function suspendRole(Request $request, PractitionerRole $role): JsonResponse
    {
        $role->update(['status' => 'suspended']);
        Audit::record($request, 'practitioner_role', 'suspend', 'success', $request->user(), ['facility_id' => $role->facility_id]);
        Notifier::send($role->practitioner?->user_id, 'security_change', 'role-'.$role->id.'-'.now()->timestamp);

        return response()->json(['data' => ['status' => 'suspended']]);
    }

    public function disableUser(Request $request, User $user): JsonResponse
    {
        $user->forceFill(['status' => 'disabled'])->save();
        $user->tokens()->delete();
        Audit::record($request, 'account', 'disable', 'success', $request->user(), ['resource_id' => (string) $user->id]);

        return response()->json(['data' => ['status' => 'disabled']]);
    }
}
