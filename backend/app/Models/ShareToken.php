<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
