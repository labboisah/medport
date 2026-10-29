<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use Illuminate\Http\JsonResponse;

/** Minimal directory of verified facilities so patients and staff can pick a recipient; no contact or internal fields. */
class FacilityDirectoryController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Facility::where('verification_status', 'verified')->orderBy('name')->limit(200)->get(['uuid', 'name']);

        return response()->json(['data' => $rows->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])]);
    }
}
