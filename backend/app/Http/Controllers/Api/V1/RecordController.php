<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\RecordCorrection;
use App\Services\Audit;
use App\Services\RecordAccess;
use App\Services\RecordPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecordController extends Controller
{
    private function respond(array $categories, Patient $patient): JsonResponse
    {
        $items = RecordPresenter::timeline($patient, $categories);

        return response()->json(['data' => $items, 'meta' => RecordPresenter::completeness($items)]);
    }

    private function requested(Request $request): array
    {
        $request->validate(['categories' => ['sometimes', 'array'], 'categories.*' => ['string', 'in:'.implode(',', array_keys(RecordPresenter::MODELS))]]);

        return $request->input('categories') ?: array_keys(RecordPresenter::MODELS);
    }

    /** Provider timeline: each category is authorized and audited separately; unshared categories are simply absent. */
    public function providerTimeline(Request $request, string $patient): JsonResponse
    {
        $record = Patient::where('uuid', $patient)->first();
        $allowed = [];
        foreach ($this->requested($request) as $category) {
            if ($record && RecordAccess::authorize($request, $record, $category)['allowed']) {
                $allowed[] = $category;
            }
        }
        if (! $allowed) {
            // Same answer for unknown patient and no consent.
            throw new HttpException(403, 'Forbidden.');
        }

        return $this->respond($allowed, $record);
    }

    public function patientTimeline(Request $request): JsonResponse
    {
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $categories = $this->requested($request);
        foreach ($categories as $category) {
            RecordAccess::authorize($request, $patient, $category);
        }

        return $this->respond($categories, $patient);
    }

    /** Patients dispute or request correction; the original is snapshotted and never overwritten. */
    public function requestCorrection(Request $request, string $category, string $record): JsonResponse
    {
        abort_unless(isset(RecordPresenter::MODELS[$category]), 404);
        $patient = $request->user()->patient ?? throw new HttpException(403, 'Forbidden.');
        $data = $request->validate([
            'kind' => ['required', 'in:dispute,correction,entered_in_error'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'proposed_change' => ['sometimes', 'array'],
        ]);

        [$type, $class] = RecordPresenter::MODELS[$category];
        $item = $class::where('uuid', $record)->where('patient_id', $patient->id)->firstOrFail();

        $correction = RecordCorrection::create([
            'patient_id' => $patient->id,
            'record_type' => $type,
            'record_id' => $item->id,
            'kind' => $data['kind'],
            'original_snapshot' => $item->toArray(),
            'proposed_change' => $data['proposed_change'] ?? null,
            'reason' => $data['reason'],
            'requested_by_user_id' => $request->user()->id,
        ]);
        Audit::record($request, 'record_correction', 'create', 'success', $request->user(), ['patient_id' => $patient->id, 'resource_id' => $correction->uuid]);

        return response()->json(['data' => ['id' => $correction->uuid, 'status' => $correction->correction_status]], 201);
    }
}
