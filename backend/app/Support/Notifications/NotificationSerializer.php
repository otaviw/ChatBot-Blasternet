<?php

declare(strict_types=1);


namespace App\Support\Notifications;

use App\Models\Notification;
use Illuminate\Support\Collection;

class NotificationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(Notification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'user_id' => (int) $notification->user_id,
            'type' => (string) $notification->type,
            'module' => (string) $notification->module,
            'title' => (string) $notification->title,
            'text' => (string) $notification->text,
            'is_read' => (bool) $notification->is_read,
            'reference_type' => $notification->reference_type,
            'reference_id' => $notification->reference_id ? (int) $notification->reference_id : null,
            'reference_meta' => is_array($notification->reference_meta) ? $notification->reference_meta : null,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'updated_at' => $notification->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, Notification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public function serializeCollection(Collection $notifications): array
    {
        return $notifications
            ->map(fn (Notification $notification) => $this->serialize($notification))
            ->values()
            ->all();
    }
}
