<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Referral;
use App\Services\Audit;
use App\Services\RecordAccess;
use App\Services\StaffContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReferralController extends Controller
{
    private function present(Referral $r): array
    {
        return [
            'id' => $r->uuid,
            'from_facility' => $r->sourceFacility?->name,
            'to_facility' => $r->toFacility?->name,
            'reason' => $r->reason,
            'status' => $r->referral_status,
            'outcome' => $r->outcome_text,
            'created_at' => $r->created_at,
            'decided_at' => $r->decided_at,
        ];
    }

    /** The destination only sees a referral while the patient's consent to share it with that destination is effective. */
    private function destinationConsent(int $patientId, int $facilityId): ?Consent
    {
        return Consent::effective()->where('patient_id', $patientId)->where('recipient_facility_id', $facilityId)
            ->where('purpose', 'direct_care')->get()->first(fn ($c) => in_array('referrals', $c->categories, true));
    }

    public function store(Request $request, string $patient): JsonResponse
    {
        $data = $request->validate([
            'to_facility_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $record = Patient::where('uuid', $patient)->first();
        $auth = $record ? RecordAccess::authorize($request, $record, 'referrals') : ['allowed' => false];
        if (! $auth['allowed']) {
            throw new HttpException(403, 'Forbidden.');
        }
        $to = Facility::where('uuid', $data['to_facility_id'])->where('verification_status', 'verified')->first();
        if (! $to || $to->id === $auth['facility_id']) {
            throw new HttpException(422, 'Destination facility is not available.');
        }
        $consent = $this->destinationConsent($record->id, $to->id);
        if (! $consent) {
            Audit::record($request, 'referral', 'create', 'denied', $request->user(), ['patient_id' => $record->id, 'facility_id' => $auth['facility_id'], 'authorization_basis' => 'no_consent_for_destination']);
            throw new HttpException(422, 'The patient has not agreed to share referrals with that facility.');
        }

        $r = (new Referral)->forceFill([
            'patient_id' => $record->id, 'to_facility_id' => $to->id, 'consent_id' => $consent->id, 'reason' => $data['reason'],
            'source_facility_id' => $auth['facility_id'], 'author_practitioner_id' => $request->user()->practitioner?->id,
            'submitted_by_user_id' => $request->user()->id, 'clinical_date' => now(), 'verification_status' => 'unverified',
        ]);
        $r->save();
        Audit::record($request, 'referral', 'create', 'success', $request->user(), ['patient_id' => $record->id, 'facility_id' => $auth['facility_id'], 'resource_id' => $r->uuid]);

        return response()->json(['data' => $this->present($r->load(['sourceFacility', 'toFacility']))], 201);
    }

    public function incoming(Request $request): JsonResponse
    {
        $role = StaffContext::role($request->user()) ?? throw new HttpException(403, 'Forbidden.');
        $rows = Referral::where('to_facility_id', $role->facility_id)->where('record_status', 'active')
            ->whereHas('consent', fn ($q) => $q->effective())->with(['sourceFacility', 'toFacility', 'patient'])->latest('id')->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r) + ['patient' => [
            'id' => $r->patient->uuid, 'given_name' => $r->patient->given_name, 'family_name' => $r->patient->family_name,
        ]])]);
    }

    public function decide(Request $request, string $referral): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:accepted,declined,completed'],
            'outcome' => ['required_if:status,completed', 'nullable', 'string', 'max:2000'],
        ]);
        $role = StaffContext::role($request->user()) ?? throw new HttpException(403, 'Forbidden.');
        $r = Referral::where('uuid', $referral)->where('to_facility_id', $role->facility_id)
            ->whereHas('consent', fn ($q) => $q->effective())->first();
        if (! $r) {
            Audit::record($request, 'referral', 'update', 'denied', $request->user(), ['facility_id' => $role->facility_id]);
            throw new HttpException(404, 'Not found.');
        }
        $allowed = ['requested' => ['accepted', 'declined'], 'accepted' => ['completed']];
        if (! in_array($data['status'], $allowed[$r->referral_status] ?? [], true)) {
            throw new HttpException(409, 'That status change is not allowed.');
        }

        $r->forceFill([
            'referral_status' => $data['status'], 'outcome_text' => $data['outcome'] ?? $r->outcome_text,
            'decided_at' => now(), 'decided_by_user_id' => $request->user()->id,
        ])->save();
        Audit::record($request, 'referral', 'update', 'success', $request->user(), ['patient_id' => $r->patient_id, 'facility_id' => $role->facility_id, 'resource_id' => $r->uuid]);

        return response()->json(['data' => $this->present($r->load(['sourceFacility', 'toFacility']))]);
    }
}
