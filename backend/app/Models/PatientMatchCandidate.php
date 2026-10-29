<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMatchCandidate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'score' => 'decimal:4', 'reviewed_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'candidate_patient_id');
    }
}
