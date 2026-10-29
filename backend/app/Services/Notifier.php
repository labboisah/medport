<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notification events and their fixed, minimal wording. Messages never contain names, facilities,
 * record types or clinical detail; the app shows details only after sign-in. Wording is a working default.
 */
class Notifier
{
    public const EVENTS = [
        'access_requested' => ['text' => 'A provider has asked to see your records. Open MediPass to decide.', 'mandatory' => false],
        'access_decided' => ['text' => 'Your request to see a patient\'s records has been answered. Open MediPass to view it.', 'mandatory' => false],
        'access_event' => ['text' => 'Your records were viewed by someone you shared them with. See your access history.', 'mandatory' => false],
        'sharing_changed' => ['text' => 'Your sharing settings changed. See your access history.', 'mandatory' => false],
        'security_change' => ['text' => 'A security-related change was made to your account. If this was not expected, contact support.', 'mandatory' => true],
    ];

    /** Sends to one user. Failing to notify never blocks the action that caused it; the queue records failures. */
    public static function send(User|int|null $user, string $event, ?string $dedupeKey = null, ?int $dedupeMinutes = null): void
    {
        $user = is_int($user) ? User::find($user) : $user;
        if (! $user || ! isset(self::EVENTS[$event])) {
            return;
        }
        if ($dedupeMinutes && ! Cache::add("notify:{$user->id}:{$event}:{$dedupeKey}", 1, now()->addMinutes($dedupeMinutes))) {
            return;
        }
        try {
            DeliverNotification::dispatch($user->id, $event, $dedupeKey && ! $dedupeMinutes ? $dedupeKey : null);
        } catch (Throwable $e) {
            Log::warning('notification dispatch failed', ['event' => $event, 'exception' => $e::class]);
        }
    }

    public static function enabled(User $user, string $event): bool
    {
        if (self::EVENTS[$event]['mandatory'] ?? false) {
            return true;
        }

        return NotificationPreference::where('user_id', $user->id)->where('event', $event)->value('in_app') ?? true;
    }
}
