<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Active means not revoked and not past expiry, regardless of the stored status flag. */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', 'active')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recipientFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'recipient_facility_id');
    }
}
