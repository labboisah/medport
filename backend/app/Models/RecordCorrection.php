<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class RecordCorrection extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['original_snapshot' => 'array', 'proposed_change' => 'array', 'resolved_at' => 'datetime'];
    }

    /** The original snapshot is write-once; only resolution fields may change. */
    protected static function booted(): void
    {
        static::updating(function (self $c): void {
            if ($c->isDirty(['original_snapshot', 'record_type', 'record_id', 'patient_id'])) {
                throw new \LogicException('Correction originals cannot be altered.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Corrections are retained.'));
    }
}
