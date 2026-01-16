<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('sanctum');
        if (!$user) {
            abort(401);
        }

        $notifications = $user->notifications()->latest()->limit(25)->get();
        return NotificationResource::collection($notifications);
    }

    public function markAsRead(Request $request, DatabaseNotification $notification)
    {
        $user = $request->user('sanctum');
        if (!$user || $notification->notifiable_id !== $user->id || $notification->notifiable_type !== get_class($user)) {
            abort(403, 'Notificação não pertence ao usuário.');
        }

        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    public function markAll(Request $request)
    {
        $user = $request->user('sanctum');
        if (!$user) {
            abort(401);
        }

        $user->unreadNotifications->markAsRead();

        return response()->json(['status' => 'ok']);
    }
}
