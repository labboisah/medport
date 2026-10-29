<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessGrant extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeEffective(Builder $query): void
    {
        $query->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
