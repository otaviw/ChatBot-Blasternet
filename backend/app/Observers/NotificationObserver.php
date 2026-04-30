<?php

declare(strict_types=1);


namespace App\Observers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use App\Support\Notifications\NotificationSerializer;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class NotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher,
        private NotificationService $notificationService,
        private NotificationSerializer $notificationSerializer
    ) {}

    public function created(Notification $notification): void
    {
        if (! $notification->user_id) {
            return;
        }

        $byModule = $this->notificationService->unreadCountByModule((int) $notification->user_id);

        $this->publisher->publish(
            RealtimeEvents::NOTIFICATION_CREATED,
            [
                "user:{$notification->user_id}",
            ],
            [
                'notification' => $this->notificationSerializer->serialize($notification),
                'unreadByModule' => $byModule,
                'totalUnread' => $this->notificationService->totalUnread($byModule),
            ]
        );
    }
}
