<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InboxNotification;
use App\Models\NotificationPreference;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = InboxNotification::where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json([
            'data' => $rows->map(fn ($n) => ['id' => $n->uuid, 'event' => $n->event, 'message' => Notifier::EVENTS[$n->event]['text'] ?? '', 'created_at' => $n->created_at, 'read' => (bool) $n->read_at]),
            'meta' => ['unread' => InboxNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        InboxNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $saved = NotificationPreference::where('user_id', $request->user()->id)->pluck('in_app', 'event');
        $data = [];
        foreach (Notifier::EVENTS as $event => $def) {
            $data[] = ['event' => $event, 'in_app' => $def['mandatory'] ? true : (bool) ($saved[$event] ?? true), 'can_disable' => ! $def['mandatory']];
        }

        return response()->json(['data' => $data, 'meta' => ['channels' => ['in_app'], 'note' => 'Email, SMS and push are not available until message content rules and providers are approved.']]);
    }

    public function updatePreference(Request $request): JsonResponse
    {
        $data = $request->validate(['event' => ['required', Rule::in(array_keys(Notifier::EVENTS))], 'in_app' => ['required', 'boolean']]);
        abort_if(Notifier::EVENTS[$data['event']]['mandatory'] && ! $data['in_app'], 422, 'Security notices cannot be turned off.');
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'event' => $data['event']], ['in_app' => $data['in_app']]);

        return $this->preferences($request);
    }
}
