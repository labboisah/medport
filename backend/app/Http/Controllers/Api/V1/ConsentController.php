<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Facility;
use App\Services\AccessDecision;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Patient-controlled, time-limited sharing. Consent wording and duration caps are working defaults awaiting legal/clinical approval. */
class ConsentController extends Controller
{
    private function patient(Request $request)
    {
        return $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
    }

    private function present(Consent $c): array
    {
        return [
            'id' => $c->uuid,
            'facility' => $c->recipientFacility?->name,
            'purpose' => $c->purpose,
            'categories' => $c->categories,
            'status' => $c->revoked_at ? 'revoked' : ($c->expires_at->isPast() ? 'expired' : $c->status),
            'granted_at' => $c->granted_at,
            'expires_at' => $c->expires_at,
            'revoked_at' => $c->revoked_at,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $patient = $this->patient($request);
        $data = $request->validate([
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'uuid')],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(AccessDecision::CATEGORIES)],
            'purpose' => ['sometimes', Rule::in(AccessDecision::PURPOSES)],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:'.(int) config('medipass.consent_max_hours')],
        ]);
        $facility = Facility::where('uuid', $data['facility_id'])->where('verification_status', 'verified')->first();
        if (! $facility) {
            throw new HttpException(422, 'Facility is not available for sharing.');
        }
        $categories = array_values(array_unique($data['categories']));

        $consent = DB::transaction(function () use ($patient, $facility, $data, $categories, $request) {
            $consent = Consent::create([
                'patient_id' => $patient->id,
                'recipient_facility_id' => $facility->id,
                'purpose' => $data['purpose'] ?? 'direct_care',
                'categories' => $categories,
                'consent_text_version' => 'draft-unapproved-0',
                'method' => 'in_app',
                'granted_at' => now(),
                'expires_at' => now()->addHours($data['duration_hours']),
                'recorded_by_user_id' => $request->user()->id,
            ]);
            AccessGrant::create([
                'consent_id' => $consent->id, 'patient_id' => $patient->id, 'facility_id' => $facility->id,
                'scope' => $categories, 'starts_at' => now(), 'expires_at' => $consent->expires_at,
            ]);
            Audit::record($request, 'consent', 'share', 'success', $request->user(), [
                'patient_id' => $patient->id, 'facility_id' => $facility->id, 'resource_id' => $consent->uuid, 'purpose' => $consent->purpose,
            ]);

            return $consent;
        });
        Notifier::send($patient->user_id, 'sharing_changed', $consent->uuid);

        return response()->json(['data' => $this->present($consent->load('recipientFacility'))], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $consents = Consent::where('patient_id', $this->patient($request)->id)->with('recipientFacility')->latest('id')->get();

        return response()->json(['data' => $consents->map(fn ($c) => $this->present($c))]);
    }

    public function revoke(Request $request, string $consent): JsonResponse
    {
        $patient = $this->patient($request);
        // Another patient's consent is indistinguishable from a missing one.
        $record = Consent::where('uuid', $consent)->where('patient_id', $patient->id)->firstOrFail();

        DB::transaction(function () use ($record, $request, $patient) {
            if ($record->status === 'active' && ! $record->revoked_at) {
                $record->update(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => $request->input('reason') ? mb_substr((string) $request->input('reason'), 0, 500) : null]);
                AccessGrant::where('consent_id', $record->id)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now()]);
                Audit::record($request, 'consent', 'revoke', 'success', $request->user(), [
                    'patient_id' => $patient->id, 'facility_id' => $record->recipient_facility_id, 'resource_id' => $record->uuid,
                ]);
            }
        });
        Notifier::send($patient->user_id, 'sharing_changed', 'revoke-'.$record->uuid);

        return response()->json(['data' => $this->present($record->fresh()->load('recipientFacility'))]);
    }

    /** Access history shows who touched the record without any clinical payload. */
    public function history(Request $request): JsonResponse
    {
        $events = AuditEvent::where('patient_id', $this->patient($request)->id)->orderByDesc('id')->limit(100)->get();
        $facilities = Facility::whereIn('id', $events->pluck('facility_id')->filter())->pluck('name', 'id');

        return response()->json(['data' => $events->map(fn ($e) => [
            'occurred_at' => $e->occurred_at,
            'action' => $e->action,
            'outcome' => $e->outcome,
            'resource_type' => $e->resource_type,
            'purpose' => $e->purpose,
            'facility' => $facilities[$e->facility_id] ?? null,
            'emergency' => $e->emergency,
        ])]);
    }
}
