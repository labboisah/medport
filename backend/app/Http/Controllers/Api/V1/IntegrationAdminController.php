<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\IntegrationEndpoint;
use App\Models\SyncJob;
use App\Services\Audit;
use App\Services\RecordPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform-admin control and visibility of partner integrations. Sandbox only until approvals exist. */
class IntegrationAdminController extends Controller
{
    private const SCOPES = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports'];

    private function present(IntegrationEndpoint $e): array
    {
        $jobs = $e->syncJobs();

        return [
            'id' => $e->uuid,
            'name' => $e->name,
            'facility' => $e->facility?->name,
            'protocol' => $e->protocol,
            'environment' => $e->environment,
            'status' => $e->status,
            'scopes' => $e->scopes ?? [],
            'key_expires_at' => $e->key_expires_at,
            'last_success_at' => $jobs->max('last_success_at'),
            'last_attempt_at' => $jobs->max('last_attempt_at'),
            'jobs' => $e->syncJobs()->selectRaw('sync_status, count(*) as total')->groupBy('sync_status')->pluck('total', 'sync_status'),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => IntegrationEndpoint::with('facility')->orderBy('name')->get()->map(fn ($e) => $this->present($e))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'uuid')],
            'name' => ['required', 'string', 'max:100'],
            'protocol' => ['required', Rule::in(['fhir_rest', 'adapter'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(self::SCOPES)],
            'valid_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);
        $facility = Facility::where('uuid', $data['facility_id'])->firstOrFail();
        abort_if(IntegrationEndpoint::where('facility_id', $facility->id)->where('name', $data['name'])->exists(), 422, 'That integration name is already used for this facility.');

        $key = 'mpk_'.Str::random(48);
        $endpoint = IntegrationEndpoint::create([
            'uuid' => (string) Str::uuid(),
            'facility_id' => $facility->id,
            'name' => $data['name'],
            'protocol' => $data['protocol'],
            'environment' => 'sandbox',
            'status' => 'active',
            'scopes' => array_map(fn ($s) => 'write:'.$s, array_values(array_unique($data['scopes']))),
            'api_key_hash' => hash('sha256', $key),
            'key_expires_at' => now()->addDays($data['valid_days'] ?? 90),
        ]);
        Audit::record($request, 'integration_endpoint', 'create', 'success', $request->user(), ['facility_id' => $facility->id, 'resource_id' => $endpoint->uuid]);

        // The key is shown once and only its hash is stored.
        return response()->json(['data' => $this->present($endpoint) + ['api_key' => $key]], 201);
    }

    public function disable(Request $request, IntegrationEndpoint $endpoint): JsonResponse
    {
        $endpoint->update(['status' => 'disabled']);
        Audit::record($request, 'integration_endpoint', 'disable', 'success', $request->user(), ['facility_id' => $endpoint->facility_id, 'resource_id' => $endpoint->uuid]);

        return response()->json(['data' => $this->present($endpoint)]);
    }

    public function jobs(IntegrationEndpoint $endpoint): JsonResponse
    {
        return response()->json(['data' => $endpoint->syncJobs()->latest('id')->limit(50)->get()->map(fn (SyncJob $j) => [
            'id' => $j->uuid,
            'status' => $j->sync_status,
            'attempts' => $j->attempts,
            'last_attempt_at' => $j->last_attempt_at,
            'last_success_at' => $j->last_success_at,
            'next_retry_at' => $j->next_retry_at,
            'dead_lettered_at' => $j->dead_lettered_at,
            'last_error_code' => $j->last_error_code,
            'received' => $j->received_count,
            'created' => $j->created_count,
            'updated' => $j->updated_count,
            'skipped_stale' => $j->skipped_stale_count,
            'rejected' => $j->rejected_count,
        ])]);
    }

    /** Re-opens a failed or dead-lettered delivery so the partner's redelivery with the same key is accepted. No payload is stored, so nothing is replayed from here. */
    public function replay(Request $request, SyncJob $job): JsonResponse
    {
        abort_unless(in_array($job->sync_status, ['failed', 'dead_letter'], true), 422, 'Only failed or dead-lettered deliveries can be replayed.');
        $job->update(['sync_status' => 'queued', 'attempts' => 0, 'next_retry_at' => null, 'dead_lettered_at' => null]);
        Audit::record($request, 'sync_job', 'replay', 'success', $request->user(), ['facility_id' => $job->endpoint->facility_id, 'resource_id' => $job->uuid]);

        return response()->json(['data' => ['id' => $job->uuid, 'status' => 'queued']]);
    }

    /** Counts of what MediPass holds from this source, to compare with the partner's own totals. */
    public function reconciliation(IntegrationEndpoint $endpoint): JsonResponse
    {
        $rows = [];
        foreach (self::SCOPES as $category) {
            $class = RecordPresenter::MODELS[$category][1];
            $q = fn () => $class::where('source_facility_id', $endpoint->facility_id)->where('source_system', $endpoint->sourceSystem());
            $rows[$category] = ['active' => $q()->where('record_status', 'active')->count(), 'entered_in_error' => $q()->where('record_status', 'entered_in_error')->count(), 'latest_source_update' => $q()->max('source_updated_at')];
        }

        return response()->json(['data' => $rows]);
    }
}
