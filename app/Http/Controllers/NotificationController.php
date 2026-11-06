<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $user = $request->user();
        $notifications = $user->notifications()
            ->when(
                ($data['unread_only'] ?? false) === true || ($data['unread_only'] ?? null) === '1',
                fn ($query) => $query->whereNull('read_at'),
            )
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json([
            'data' => [
                'id' => $record->id,
                'read_at' => $record->read_at,
            ],
        ]);
    }
}
