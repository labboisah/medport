<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Consent;
use App\Models\Patient;
use App\Services\AccessDecision;
use App\Services\Audit;
use App\Services\Notifier;
use App\Services\StaffContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccessRequestController extends Controller
{
    private function present(AccessRequest $r, bool $forPatient): array
    {
        $expired = $r->status === 'pending' && $r->expires_at->isPast();

        return [
            'id' => $r->uuid,
            'facility' => $r->facility?->name,
            'requested_by' => $forPatient && $r->practitioner ? trim($r->practitioner->given_name.' '.$r->practitioner->family_name) : null,
            'purpose' => $r->purpose,
            'categories' => $r->categories,
            'reason' => $r->reason,
            'requested_hours' => $r->requested_hours,
            'status' => $expired ? 'expired' : $r->status,
            'expires_at' => $r->expires_at,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', 'in:'.implode(',', AccessDecision::CATEGORIES)],
            'purpose' => ['sometimes', 'in:'.implode(',', AccessDecision::PURPOSES)],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:'.(int) config('medipass.consent_max_hours')],
        ]);
        $role = StaffContext::role($request->user());
        if (! $role) {
            Audit::record($request, 'access_request', 'create', 'denied', $request->user());
            throw new HttpException(403, 'Forbidden.');
        }

        $patient = Patient::where('uuid', $data['patient_id'])->where('status', 'active')->first();
        // A pending duplicate is not created twice; unknown patients get the same acknowledgement.
        if ($patient && ! AccessRequest::where('patient_id', $patient->id)->where('facility_id', $role->facility_id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
            $r = AccessRequest::create([
                'patient_id' => $patient->id, 'facility_id' => $role->facility_id, 'practitioner_id' => $role->practitioner_id,
                'purpose' => $data['purpose'] ?? 'direct_care', 'categories' => array_values(array_unique($data['categories'])),
                'reason' => $data['reason'], 'requested_hours' => $data['duration_hours'],
                'expires_at' => now()->addHours((int) config('medipass.access_request_hours')),
            ]);
            Audit::record($request, 'access_request', 'create', 'success', $request->user(), ['patient_id' => $patient->id, 'facility_id' => $role->facility_id, 'resource_id' => $r->uuid, 'purpose' => $r->purpose]);
            Notifier::send($patient->user_id, 'access_requested', $r->uuid);
        }

        return response()->json(['data' => ['status' => 'request_received']], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $rows = AccessRequest::where('patient_id', $patient->id)->with(['facility', 'practitioner'])->latest('id')->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r, true))]);
    }

    public function approve(Request $request, string $accessRequest): JsonResponse
    {
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $data = $request->validate([
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['string'],
            'duration_hours' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('medipass.consent_max_hours')],
        ]);
        $r = AccessRequest::where('uuid', $accessRequest)->where('patient_id', $patient->id)->firstOrFail();
        if ($r->status !== 'pending' || $r->expires_at->isPast()) {
            throw new HttpException(409, 'Request is no longer pending.');
        }

        // The patient may limit scope but never widen it beyond what was asked.
        $categories = isset($data['categories']) ? array_values(array_intersect($data['categories'], $r->categories)) : $r->categories;
        if (! $categories || (isset($data['categories']) && array_diff($data['categories'], $r->categories))) {
            throw new HttpException(422, 'Categories must be a subset of those requested.');
        }
        $hours = min($data['duration_hours'] ?? $r->requested_hours, $r->requested_hours);

        DB::transaction(function () use ($r, $patient, $categories, $hours, $request) {
            $consent = Consent::create([
                'patient_id' => $patient->id, 'recipient_facility_id' => $r->facility_id, 'recipient_practitioner_id' => $r->practitioner_id,
                'purpose' => $r->purpose, 'categories' => $categories, 'consent_text_version' => 'draft-unapproved-0', 'method' => 'in_app',
                'granted_at' => now(), 'expires_at' => now()->addHours($hours), 'recorded_by_user_id' => $request->user()->id,
            ]);
            AccessGrant::create([
                'consent_id' => $consent->id, 'patient_id' => $patient->id, 'facility_id' => $r->facility_id, 'practitioner_id' => $r->practitioner_id,
                'scope' => $categories, 'starts_at' => now(), 'expires_at' => $consent->expires_at,
            ]);
            $r->update(['status' => 'approved', 'consent_id' => $consent->id, 'decided_at' => now()]);
            Audit::record($request, 'access_request', 'approve', 'success', $request->user(), ['patient_id' => $patient->id, 'facility_id' => $r->facility_id, 'resource_id' => $r->uuid, 'purpose' => $r->purpose]);
        });
        Notifier::send($r->practitioner?->user_id, 'access_decided', $r->uuid);

        return response()->json(['data' => $this->present($r->fresh()->load(['facility', 'practitioner']), true)]);
    }

    public function deny(Request $request, string $accessRequest): JsonResponse
    {
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $r = AccessRequest::where('uuid', $accessRequest)->where('patient_id', $patient->id)->firstOrFail();
        if ($r->status !== 'pending') {
            throw new HttpException(409, 'Request is no longer pending.');
        }
        $r->update(['status' => 'denied', 'decided_at' => now()]);
        Audit::record($request, 'access_request', 'deny', 'success', $request->user(), ['patient_id' => $patient->id, 'facility_id' => $r->facility_id, 'resource_id' => $r->uuid]);
        Notifier::send($r->practitioner?->user_id, 'access_decided', $r->uuid);

        return response()->json(['data' => $this->present($r->load(['facility', 'practitioner']), true)]);
    }

    /** The requester sees only the state of its own requests, never the patient's reasons. */
    public function mine(Request $request): JsonResponse
    {
        $role = StaffContext::role($request->user()) ?? throw new HttpException(403, 'Forbidden.');
        $rows = AccessRequest::where('practitioner_id', $role->practitioner_id)->with('facility')->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r, false))]);
    }
}
