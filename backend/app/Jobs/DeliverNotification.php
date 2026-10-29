<?php

namespace App\Jobs;

use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;

/** Idempotent: a repeated run with the same dedupe key creates nothing. Only ids travel on the queue, never message text. */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public string $event, public ?string $dedupeKey = null) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || $user->status === 'disabled' || ! Notifier::enabled($user, $this->event)) {
            return;
        }
        try {
            InboxNotification::create(['user_id' => $user->id, 'event' => $this->event, 'dedupe_key' => $this->dedupeKey]);
        } catch (UniqueConstraintViolationException) {
            // Already delivered by an earlier attempt.
        }
    }
}
