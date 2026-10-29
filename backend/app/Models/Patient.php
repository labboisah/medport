<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'identity_verified_at' => 'datetime'];
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_patient_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }
}
