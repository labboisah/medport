<?php

namespace App\Models;

use App\Models\Concerns\HasProvenance;
use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    use HasProvenance;

    protected function casts(): array
    {
        return ['value_number' => 'decimal:4', 'reference_low' => 'decimal:4', 'reference_high' => 'decimal:4'];
    }
}
