<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\Incident;
use App\Models\IntegrationEndpoint;
use App\Models\PolicySetting;
use App\Models\Practitioner;
use App\Models\SyncJob;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin-only operations: metrics, queue inspection and safe retry, incidents, policy settings, audit review. Never returns payloads or clinical data. */
class OperationsController extends Controller
{
    /** Adjustable working defaults: config key => [min, max]. Changes are audited. Retention values are recorded only; nothing is deleted automatically. */
    public const POLICY = [
        'consent_max_hours' => ['config' => 'medipass.consent_max_hours', 'min' => 1, 'max' => 8760],
        'share_token_minutes' => ['config' => 'medipass.share_token_minutes', 'min' => 1, 'max' => 60],
        'stale_after_days' => ['config' => 'medipass.stale_after_days', 'min' => 1, 'max' => 3650],
        'access_request_hours' => ['config' => 'medipass.access_request_hours', 'min' => 1, 'max' => 720],
        'retention_audit_days' => ['config' => null, 'min' => 30, 'max' => 36500],
        'retention_documents_days' => ['config' => null, 'min' => 30, 'max' => 36500],
        'retention_notifications_days' => ['config' => null, 'min' => 1, 'max' => 3650],
    ];

    public function metrics(): JsonResponse
    {
        $since = now()->subDay();
        $oldest = DB::table('jobs')->min('available_at');
        $disk = storage_path();
        $free = @disk_free_space($disk);
        $total = @disk_total_space($disk);
        $t = microtime(true);
        DB::select('select 1');

        $endpoints = IntegrationEndpoint::get();

        return response()->json(['data' => [
            'generated_at' => now(),
            'database_ms' => round((microtime(true) - $t) * 1000, 1),
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'lag_seconds' => $oldest ? max(0, now()->timestamp - (int) $oldest) : 0,
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'sync' => [
                'failed' => SyncJob::where('sync_status', 'failed')->count(),
                'dead_letter' => SyncJob::where('sync_status', 'dead_letter')->count(),
                'partial_24h' => SyncJob::where('sync_status', 'partial')->where('updated_at', '>=', $since)->count(),
            ],
            'integrations' => [
                'active' => $endpoints->where('status', 'active')->count(),
                'disabled' => $endpoints->where('status', 'disabled')->count(),
                'keys_expired' => $endpoints->filter(fn ($e) => $e->key_expires_at?->isPast())->count(),
            ],
            'security_24h' => [
                'failed_logins' => AuditEvent::where('resource_type', 'session')->where('action', 'login')->where('outcome', 'denied')->where('occurred_at', '>=', $since)->count(),
                'denied_record_reads' => AuditEvent::where('resource_type', 'patient_record')->where('outcome', 'denied')->where('occurred_at', '>=', $since)->count(),
                'integration_denials' => AuditEvent::where('resource_type', 'integration_ingest')->where('outcome', 'denied')->where('occurred_at', '>=', $since)->count(),
            ],
            'storage' => ['free_percent' => $total ? round($free / $total * 100, 1) : null],
            'verification_backlog' => ['facilities' => Facility::where('verification_status', '!=', 'verified')->count(), 'practitioners' => Practitioner::where('verification_status', '!=', 'verified')->count()],
            'open_incidents' => Incident::whereNotIn('status', ['resolved'])->count(),
        ]]);
    }

    /** Only the job type, queue and exception class are shown, because exception text and payloads can contain personal data. */
    public function failedJobs(): JsonResponse
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(100)->get()->map(fn ($j) => [
            'id' => $j->uuid,
            'queue' => $j->queue,
            'job' => class_basename(json_decode($j->payload, true)['displayName'] ?? 'unknown'),
            'exception' => explode(':', (string) strtok($j->exception, "\n"))[0],
            'failed_at' => $j->failed_at,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function retryFailed(Request $request, string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::record($request, 'queue_job', 'retry', 'success', $request->user(), ['resource_id' => $uuid]);

        return response()->json(['data' => ['id' => $uuid, 'status' => 'requeued']]);
    }

    public function incidents(): JsonResponse
    {
        return response()->json(['data' => Incident::latest('id')->limit(100)->get()->map(fn ($i) => $this->incident($i))]);
    }

    private function incident(Incident $i): array
    {
        return ['id' => $i->uuid, 'category' => $i->category, 'severity' => $i->severity, 'status' => $i->status, 'title' => $i->title, 'summary' => $i->summary, 'created_at' => $i->created_at, 'resolved_at' => $i->resolved_at];
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['account_compromise', 'suspected_breach', 'partner_outage', 'incorrect_patient_match', 'failed_sync', 'emergency_access_review', 'other'])],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'title' => ['required', 'string', 'max:150'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        $incident = Incident::create($data + ['reported_by_user_id' => $request->user()->id]);
        Audit::record($request, 'incident', 'create', 'success', $request->user(), ['resource_id' => $incident->uuid]);

        return response()->json(['data' => $this->incident($incident)], 201);
    }

    public function updateIncident(Request $request, Incident $incident): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'investigating', 'contained', 'resolved'])], 'summary' => ['sometimes', 'nullable', 'string', 'max:4000']]);
        $incident->update($data + ['resolved_at' => $data['status'] === 'resolved' ? now() : null]);
        Audit::record($request, 'incident', 'update', 'success', $request->user(), ['resource_id' => $incident->uuid]);

        return response()->json(['data' => $this->incident($incident)]);
    }

    public function policy(): JsonResponse
    {
        $saved = PolicySetting::pluck('value', 'key');
        $rows = [];
        foreach (self::POLICY as $key => $def) {
            $rows[] = ['key' => $key, 'value' => $saved[$key] ?? ($def['config'] ? config($def['config']) : null), 'min' => $def['min'], 'max' => $def['max'], 'enforced' => (bool) $def['config'], 'working_default' => ! isset($saved[$key])];
        }

        return response()->json(['data' => $rows]);
    }

    public function updatePolicy(Request $request, string $key): JsonResponse
    {
        $def = self::POLICY[$key] ?? abort(404);
        $data = $request->validate(['value' => ['required', 'integer', 'min:'.$def['min'], 'max:'.$def['max']]]);
        PolicySetting::updateOrCreate(['key' => $key], ['value' => $data['value'], 'updated_by_user_id' => $request->user()->id]);
        if ($def['config']) {
            config([$def['config'] => $data['value']]);
        }
        Audit::record($request, 'policy_setting', 'update', 'success', $request->user(), ['resource_id' => $key]);

        return $this->policy();
    }

    /** Pending verification lists, so reviewers do not need identifiers up front. */
    public function pendingVerification(): JsonResponse
    {
        return response()->json(['data' => [
            'facilities' => Facility::where('verification_status', '!=', 'verified')->orderBy('name')->get()->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name, 'type' => $f->type, 'status' => $f->verification_status]),
            'practitioners' => Practitioner::where('verification_status', '!=', 'verified')->get()->map(fn ($p) => ['id' => $p->uuid, 'name' => trim($p->given_name.' '.$p->family_name), 'status' => $p->verification_status]),
        ]]);
    }

    /** Incident-review view of the audit trail: metadata only. */
    public function audit(Request $request): JsonResponse
    {
        $q = AuditEvent::query()->orderByDesc('id');
        foreach (['resource_type', 'action', 'outcome'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, (string) $request->input($f));
            }
        }
        $rows = $q->limit(100)->get()->map(fn ($e) => ['id' => $e->uuid, 'at' => $e->occurred_at, 'actor_type' => $e->actor_type, 'resource_type' => $e->resource_type, 'action' => $e->action, 'outcome' => $e->outcome, 'purpose' => $e->purpose, 'authorization_basis' => $e->authorization_basis, 'correlation_id' => $e->correlation_id]);

        return response()->json(['data' => $rows]);
    }
}
