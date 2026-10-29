<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncJob extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['last_attempt_at' => 'datetime', 'last_success_at' => 'datetime', 'next_retry_at' => 'datetime', 'dead_lettered_at' => 'datetime', 'result' => 'array'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationEndpoint::class, 'integration_endpoint_id');
    }
}
