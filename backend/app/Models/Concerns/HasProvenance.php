<?php

namespace App\Models\Concerns;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\RecordCorrection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Shared provenance, freshness and correction behaviour for clinical items. */
trait HasProvenance
{
    use HasPublicUuid;

    public function initializeHasProvenance(): void
    {
        $this->mergeCasts(['clinical_date' => 'datetime', 'ingested_at' => 'datetime']);
        $this->guarded = ['id', 'uuid'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function sourceFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'source_facility_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'author_practitioner_id');
    }

    public function corrections(): MorphMany
    {
        return $this->morphMany(RecordCorrection::class, 'record', 'record_type', 'record_id');
    }

    /** Excludes items marked entered-in-error or superseded; they stay stored for history. */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('record_status', 'active');
    }

    /** Freshness threshold is a clinical policy, so the caller must supply it. */
    public function scopeStale(Builder $query, int $days): void
    {
        $query->where('clinical_date', '<', now()->subDays($days));
    }

    public function isVerified(): bool
    {
        return in_array($this->verification_status, ['source_verified', 'clinician_verified'], true);
    }
}
