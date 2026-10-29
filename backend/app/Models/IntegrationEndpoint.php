<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class IntegrationEndpoint extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['credential_ref'];
}
