<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Notifications\NotificationSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private NotificationSerializer $notificationSerializer
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'module' => ['nullable', 'string', 'max:80'],
            'unread' => ['nullable', 'boolean'],
        ]);

        $notifications = $this->notificationService->listForUser(
            $user,
            (int) ($validated['limit'] ?? 50),
            $validated['module'] ?? null,
            array_key_exists('unread', $validated) ? (bool) $validated['unread'] : null
        );

        return response()->json([
            'ok' => true,
            'notifications' => $this->notificationSerializer->serializeCollection($notifications),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if ((int) $notification->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Notificacao nao encontrada.',
            ], 404);
        }

        $updated = $this->notificationService->markAsRead($notification);
        $byModule = $this->notificationService->unreadCountByModule($user);

        return response()->json([
            'ok' => true,
            'notification' => $this->notificationSerializer->serialize($updated),
            'unread_by_module' => $byModule,
            'total_unread' => $this->notificationService->totalUnread($byModule),
        ]);
    }

    public function unreadCounts(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $byModule = $this->notificationService->unreadCountByModule($user);

        return response()->json([
            'ok' => true,
            'unread_by_module' => $byModule,
            'total_unread' => $this->notificationService->totalUnread($byModule),
        ]);
    }

    public function markReadByReference(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $validated = $request->validate([
            'module' => ['required', 'string', 'max:80'],
            'reference_type' => ['required', 'string', 'max:80'],
            'reference_id' => ['required', 'integer', 'min:1'],
        ]);

        $markedCount = $this->notificationService->markAllAsReadByReference(
            $user,
            (string) $validated['module'],
            (string) $validated['reference_type'],
            (int) $validated['reference_id']
        );

        $byModule = $this->notificationService->unreadCountByModule($user);

        return response()->json([
            'ok' => true,
            'marked_count' => $markedCount,
            'unread_by_module' => $byModule,
            'total_unread' => $this->notificationService->totalUnread($byModule),
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect' => '/entrar',
        ], 403);
    }
}
