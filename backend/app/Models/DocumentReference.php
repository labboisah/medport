<?php

namespace App\Models;

use App\Models\Concerns\HasProvenance;
use Illuminate\Database\Eloquent\Model;

class DocumentReference extends Model
{
    use HasProvenance;

    protected $hidden = ['storage_path'];
}
