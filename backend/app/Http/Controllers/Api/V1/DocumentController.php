<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentReference;
use App\Models\Patient;
use App\Services\Audit;
use App\Services\DocumentInspector;
use App\Services\RecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentController extends Controller
{
    public function upload(Request $request, string $patient)
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['required', 'string', 'max:200'],
            'document_type' => ['required', 'in:discharge_summary,imaging_report,scan,other'],
            'clinical_date' => ['sometimes', 'date'],
        ]);
        $record = Patient::where('uuid', $patient)->first();
        // Writing a document needs the same consent-backed decision as reading that category.
        $auth = $record ? RecordAccess::authorize($request, $record, 'documents') : ['allowed' => false];
        if (! $auth['allowed']) {
            throw new HttpException(403, 'Forbidden.');
        }

        $file = $request->file('file');
        $check = DocumentInspector::inspect($file->getRealPath(), (int) config('medipass.document_max_bytes'));
        if (! $check['ok']) {
            Audit::record($request, 'document', 'upload', 'denied', $request->user(), ['patient_id' => $record->id, 'facility_id' => $auth['facility_id'], 'authorization_basis' => 'rejected_'.$check['reason']]);
            throw new HttpException(422, 'File was rejected.');
        }

        $uuid = (string) Str::uuid();
        $path = 'documents/'.$uuid.'.bin'; // generated key; the client filename is never used
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $doc = (new DocumentReference)->forceFill([
            'uuid' => $uuid,
            'patient_id' => $record->id,
            'source_facility_id' => $auth['facility_id'],
            'author_practitioner_id' => $request->user()->practitioner?->id,
            'submitted_by_user_id' => $request->user()->id,
            'clinical_date' => $data['clinical_date'] ?? now(),
            'verification_status' => 'unverified',
            'document_type' => $data['document_type'],
            'title' => $data['title'],
            'storage_disk' => 'local',
            'storage_path' => $path,
            'mime_type' => $check['mime'],
            'size_bytes' => filesize($file->getRealPath()),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'clean',
        ]);
        $doc->save();
        Audit::record($request, 'document', 'upload', 'success', $request->user(), ['patient_id' => $record->id, 'facility_id' => $auth['facility_id'], 'resource_id' => $doc->uuid]);

        return response()->json(['data' => ['id' => $doc->uuid, 'scan_status' => $doc->scan_status]], 201);
    }

    /** Authorization runs on every download; there are no public or signed URLs. */
    public function download(Request $request, string $document)
    {
        $doc = DocumentReference::where('uuid', $document)->first();
        $patient = $doc ? Patient::find($doc->patient_id) : null;
        $auth = $patient ? RecordAccess::authorize($request, $patient, 'documents') : ['allowed' => false];
        if (! $doc || ! $auth['allowed'] || $doc->scan_status !== 'clean' || $doc->record_status !== 'active') {
            throw new HttpException(404, 'Not found.');
        }
        Audit::record($request, 'document', 'download', 'success', $request->user(), ['patient_id' => $patient->id, 'facility_id' => $auth['facility_id'], 'resource_id' => $doc->uuid]);

        return Storage::disk($doc->storage_disk)->download($doc->storage_path, Str::slug($doc->title ?: 'document').'.'.($doc->mime_type === 'application/pdf' ? 'pdf' : ($doc->mime_type === 'image/png' ? 'png' : 'jpg')), [
            'Content-Type' => $doc->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
