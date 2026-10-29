<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\ShareToken;
use App\Services\AccessDecision;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccessController extends Controller
{
    /** The single gate for clinical categories. Phase 6 record endpoints must call this before returning any payload. */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'category' => ['required', 'string', 'max:50'],
            'purpose' => ['required', 'string', 'max:30'],
        ]);

        $patient = Patient::where('uuid', $data['patient_id'])->first();
        $decision = $patient
            ? AccessDecision::decide($request->user(), $patient, $data['category'], $data['purpose'])
            : ['allowed' => false, 'reason' => 'unknown_patient', 'facility_id' => null, 'grant_id' => null];

        // The audit write happens before the answer is returned; if it fails the request fails closed.
        Audit::record($request, 'patient_record', 'read', $decision['allowed'] ? 'success' : 'denied', $request->user(), [
            'patient_id' => $patient?->id,
            'facility_id' => $decision['facility_id'],
            'resource_id' => substr($data['category'], 0, 50),
            'purpose' => in_array($data['purpose'], AccessDecision::PURPOSES, true) ? $data['purpose'] : null,
            'authorization_basis' => $decision['reason'],
        ]);

        // Denial reasons are not returned, so callers cannot probe patient existence or consent state.
        return response()->json(['data' => ['allowed' => $decision['allowed']]], $decision['allowed'] ? 200 : 403);
    }

    public function createToken(Request $request): JsonResponse
    {
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $plain = Str::random(40);
        $token = ShareToken::create([
            'patient_id' => $patient->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes((int) config('medipass.share_token_minutes')),
        ]);
        Audit::record($request, 'share_token', 'create', 'success', $request->user(), ['patient_id' => $patient->id]);

        return response()->json(['data' => ['token' => $plain, 'expires_at' => $token->expires_at]], 201);
    }

    /** Scanning only identifies the patient reference; it never authorizes access to clinical data. */
    public function resolveToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $token = ShareToken::where('token_hash', hash('sha256', $data['token']))->where('expires_at', '>', now())->first();
        $patient = $token ? Patient::find($token->patient_id) : null;

        Audit::record($request, 'share_token', 'resolve', $patient ? 'success' : 'denied', $request->user(), ['patient_id' => $patient?->id]);
        if (! $patient) {
            throw new HttpException(404, 'Not found.');
        }

        return response()->json(['data' => ['patient_id' => $patient->uuid, 'access' => 'consent_required']]);
    }
}
