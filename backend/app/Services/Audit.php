<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Audit
{
    public static function record(Request $request, string $resource, string $action, string $outcome, ?User $actor = null, array $extra = []): void
    {
        AuditEvent::create($extra + [
            'uuid' => (string) Str::uuid(),
            'actor_type' => $actor ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'resource_type' => $resource,
            'action' => $action,
            'outcome' => $outcome,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
