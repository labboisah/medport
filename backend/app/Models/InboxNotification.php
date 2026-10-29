<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    use HasPublicUuid;

    protected $table = 'notifications_inbox';

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
