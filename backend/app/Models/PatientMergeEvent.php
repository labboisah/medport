<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class PatientMergeEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
