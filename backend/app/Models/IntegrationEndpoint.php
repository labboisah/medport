<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationEndpoint extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['credential_ref', 'api_key_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'key_expires_at' => 'datetime'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class);
    }

    public function allows(string $category): bool
    {
        return in_array('write:'.$category, $this->scopes ?? [], true);
    }

    public function sourceSystem(): string
    {
        return $this->protocol === 'adapter' ? 'mediflow' : 'fhir_rest';
    }
}
