<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use AppendOnly, HasPublicUuid;

    public $timestamps = false;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'emergency' => 'boolean'];
    }
}
