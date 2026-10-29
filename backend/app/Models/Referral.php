<?php

namespace App\Models;

use App\Models\Concerns\HasProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use HasProvenance;

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function toFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'to_facility_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }
}
