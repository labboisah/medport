<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\FhirAdapter;
use App\Integrations\MalformedPayload;
use App\Integrations\MediflowSyntheticAdapter;
use App\Integrations\PartnerAdapter;
use App\Models\IntegrationEndpoint;
use App\Models\SyncJob;
use App\Services\Audit;
use App\Services\IngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Partner delivery endpoint: scoped key, idempotency key, bounded retries, and no clinical data in sync status. */
class IntegrationIngestController extends Controller
{
    private function fail(Request $request, int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'correlation_id' => $request->attributes->get('correlation_id')]], $status, $headers);
    }

    private function adapter(IntegrationEndpoint $endpoint): PartnerAdapter
    {
        return $endpoint->protocol === 'adapter' ? new MediflowSyntheticAdapter : new FhirAdapter;
    }

    public function __invoke(Request $request, IngestService $ingest): JsonResponse
    {
        /** @var IntegrationEndpoint $endpoint */
        $endpoint = $request->attributes->get('integration');
        $audit = fn (string $action, string $outcome) => Audit::record($request, 'integration_ingest', $action, $outcome, null, ['actor_type' => 'integration', 'actor_id' => $endpoint->id, 'facility_id' => $endpoint->facility_id]);

        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $key)) {
            return $this->fail($request, 422, 'idempotency_key_required', 'Send an Idempotency-Key header of 8 to 100 characters.');
        }
        $hash = hash('sha256', $request->getContent());

        $job = SyncJob::where('integration_endpoint_id', $endpoint->id)->where('idempotency_key', $key)->first();
        if ($job) {
            if ($job->payload_hash !== $hash) {
                return $this->fail($request, 422, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different body.');
            }
            if (in_array($job->sync_status, ['succeeded', 'partial'], true)) {
                return response()->json($job->result, 200, ['Idempotent-Replay' => 'true']);
            }
            if ($job->sync_status === 'dead_letter') {
                return $this->fail($request, 409, 'dead_letter', 'Retries were exhausted. Ask the platform administrator to replay this delivery.');
            }
            if ($job->next_retry_at?->isFuture()) {
                return $this->fail($request, 503, 'retry_later', 'Retry later.', ['Retry-After' => (string) max(1, now()->diffInSeconds($job->next_retry_at))]);
            }
        }

        try {
            $items = $this->adapter($endpoint)->normalize($request->json()->all());
        } catch (MalformedPayload) {
            $audit('ingest', 'denied');

            return $this->fail($request, 422, 'malformed_payload', 'The delivery could not be understood.');
        }
        if (count($items) > config('medipass.integration_max_items')) {
            return $this->fail($request, 422, 'too_many_items', 'Too many items in one delivery.');
        }
        foreach ($items as $item) {
            if (isset($item['category']) && ! $endpoint->allows($item['category'])) {
                $audit('ingest', 'denied');

                return $this->fail($request, 403, 'scope_denied', 'This key is not allowed to write that kind of record.');
            }
        }

        $job ??= SyncJob::create(['uuid' => (string) Str::uuid(), 'integration_endpoint_id' => $endpoint->id, 'direction' => 'push', 'idempotency_key' => $key, 'payload_hash' => $hash]);
        $job->update(['sync_status' => 'running', 'attempts' => $job->attempts + 1, 'last_attempt_at' => now(), 'next_retry_at' => null]);

        $counts = ['created' => 0, 'updated' => 0, 'skipped_stale' => 0, 'rejected' => 0];
        $errors = [];

        try {
            foreach ($items as $i => $item) {
                $outcome = isset($item['error']) ? $item['error'] : DB::transaction(fn () => $ingest->apply($endpoint, $item));
                if (isset($counts[$outcome])) {
                    $counts[$outcome]++;
                } else {
                    $counts['rejected']++;
                    $errors[] = ['index' => $i, 'code' => $outcome]; // code only, never the record content
                }
            }
        } catch (Throwable) {
            $exhausted = $job->attempts >= config('medipass.integration_max_attempts');
            $job->update([
                'sync_status' => $exhausted ? 'dead_letter' : 'failed',
                'last_error_code' => 'processing_error',
                'next_retry_at' => $exhausted ? null : now()->addSeconds(config('medipass.integration_backoff_seconds') * (2 ** ($job->attempts - 1))),
                'dead_lettered_at' => $exhausted ? now() : null,
            ]);
            $audit('ingest', 'error');

            return $this->fail($request, 503, 'processing_error', 'The delivery could not be processed. Retry later.', ['Retry-After' => (string) config('medipass.integration_backoff_seconds')]);
        }

        $status = $counts['rejected'] > 0 ? 'partial' : 'succeeded';
        $body = ['data' => ['sync_id' => $job->uuid, 'status' => $status, 'received' => count($items)] + $counts + ['errors' => $errors]];
        $job->update([
            'sync_status' => $status,
            'last_success_at' => now(),
            'last_error_code' => $errors[0]['code'] ?? null,
            'received_count' => count($items),
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'skipped_stale_count' => $counts['skipped_stale'],
            'rejected_count' => $counts['rejected'],
            'result' => $body,
        ]);
        $audit('ingest', 'success');

        return response()->json($body);
    }
}
