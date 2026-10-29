<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function practitionerRoles(): HasMany
    {
        return $this->hasMany(PractitionerRole::class);
    }
}
