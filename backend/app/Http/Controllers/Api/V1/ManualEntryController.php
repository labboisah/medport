<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Audit;
use App\Services\RecordAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** No-EHR portal entry. Records are labelled staff-entered and unverified; they never claim a source system. */
class ManualEntryController extends Controller
{
    public function encounter(Request $request, string $patient): JsonResponse
    {
        $data = $request->validate([
            'encounter_class' => ['required', 'in:outpatient,inpatient,emergency,lab,pharmacy'],
            'clinical_date' => ['required', 'date', 'before_or_equal:now'],
            'ended_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:clinical_date'],
            'reason' => ['required', 'string', 'max:255'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ]);
        $record = Patient::where('uuid', $patient)->first();
        $auth = $record ? RecordAccess::authorize($request, $record, 'encounters') : ['allowed' => false];
        if (! $auth['allowed']) {
            throw new HttpException(403, 'Forbidden.');
        }

        $date = Carbon::parse($data['clinical_date']);
        $duplicate = Encounter::where('patient_id', $record->id)->where('source_facility_id', $auth['facility_id'])
            ->where('encounter_class', $data['encounter_class'])->whereBetween('clinical_date', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->exists();
        if ($duplicate && ! ($data['confirm_duplicate'] ?? false)) {
            return response()->json(['error' => ['code' => 'possible_duplicate', 'message' => 'A similar encounter already exists for this day. Confirm to save anyway.', 'correlation_id' => $request->attributes->get('correlation_id')]], 409);
        }

        $e = (new Encounter)->forceFill([
            'patient_id' => $record->id, 'source_facility_id' => $auth['facility_id'], 'source_system' => null,
            'author_practitioner_id' => $request->user()->practitioner?->id, 'submitted_by_user_id' => $request->user()->id,
            'clinical_date' => $date, 'verification_status' => 'unverified', 'verification_method' => 'manual_portal_entry',
            'encounter_class' => $data['encounter_class'], 'ended_at' => $data['ended_at'] ?? null, 'reason' => $data['reason'],
        ]);
        $e->save();
        Audit::record($request, 'encounter', 'create', 'success', $request->user(), ['patient_id' => $record->id, 'facility_id' => $auth['facility_id'], 'resource_id' => $e->uuid]);

        return response()->json(['data' => ['id' => $e->uuid, 'origin' => 'staff_entered', 'verification_status' => 'unverified']], 201);
    }
}
