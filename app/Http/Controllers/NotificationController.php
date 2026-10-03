<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * In-app notification inbox.
 *
 * Response shape is fixed by the mobile client, which already parses
 * { id, type, title, body, created_at, is_read }. Keep those keys.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * GET /api/notifications
     *
     * Newest first. ?unread=1 filters to unread only; ?limit caps the page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $limit = min(
            max((int) $request->query('limit', self::PER_PAGE), 1),
            100
        );

        $query = $user->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $notifications = $rows->map(fn ($n) => $this->present($n))->values();

        return response()->json([
            'status'        => 'success',
            'notifications' => $notifications,
            'unread_count'  => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     * Cheap endpoint for the header bell badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'status'       => 'success',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     *
     * Scoped to the caller's own notifications: an id belonging to someone
     * else returns 404 rather than 403 so ids cannot be probed.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // The column is a uuid: an unparseable id would raise a Postgres
        // "invalid input syntax" error instead of a clean 404.
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification = $user->notifications()->whereKey($id)->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        // markAsRead() is idempotent, so re-reading an already-read item is fine.
        $notification->markAsRead();

        return response()->json([
            'status'       => 'success',
            'notification' => $this->present($notification->fresh()),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $updated = $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'status'       => 'success',
            'updated'      => $updated,
            'unread_count' => 0,
        ]);
    }

    /**
     * Shape a stored notification for the clients.
     *
     * `type` is a stable lower_snake key derived from the class name so the
     * mobile app can switch on it without coupling to PHP class names.
     *
     * @return array<string,mixed>
     */
    private function present($notification): array
    {
        $data = is_array($notification->data)
            ? $notification->data
            : (json_decode((string) $notification->data, true) ?: []);

        return [
            'id'         => (string) $notification->id,
            'type'       => $this->typeKey((string) $notification->type),
            'title'      => (string) ($data['title'] ?? ''),
            // The mobile client reads `body` and falls back to `message`.
            'body'       => (string) ($data['body'] ?? $data['message'] ?? ''),
            'url'        => $data['url'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'booking_code' => $data['booking_code'] ?? null,
            'booking_status' => $data['booking_status'] ?? null,
            // The mobile client reads `is_read` and falls back to `read`.
            'is_read'    => $notification->read_at !== null,
            'read'       => $notification->read_at !== null,
            'read_at'    => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }

    /**
     * App\Notifications\BookingConfirmed -> booking_confirmed
     */
    private function typeKey(string $class): string
    {
        $short = class_basename($class);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    private function user(Request $request): ?User
    {
        $user = $request->user('sanctum') ?? $request->user();

        return $user instanceof User ? $user : null;
    }
}
