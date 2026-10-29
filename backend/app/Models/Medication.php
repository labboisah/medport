<?php

namespace App\Models;

use App\Models\Concerns\HasProvenance;
use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    use HasProvenance;
}
