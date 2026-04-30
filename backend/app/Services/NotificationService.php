<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
    /**
     * @param array<string, mixed> $data
     */
    public function createForUser(User|int $user, array $data): Notification
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $notification = Notification::create([
            'user_id' => $userId,
            'type' => (string) ($data['type'] ?? 'generic'),
            'module' => (string) ($data['module'] ?? 'general'),
            'title' => (string) ($data['title'] ?? ''),
            'text' => (string) ($data['text'] ?? ''),
            'is_read' => false,
            'reference_type' => $this->normalizeNullableString($data['reference_type'] ?? null),
            'reference_id' => isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            'reference_meta' => is_array($data['reference_meta'] ?? null) ? $data['reference_meta'] : null,
            'read_at' => null,
        ]);

        $this->forgetUnreadCache($userId);

        return $notification;
    }

    public function markAsRead(Notification $notification): Notification
    {
        if ($notification->is_read) {
            return $notification;
        }

        $notification->is_read = true;
        $notification->read_at = Carbon::now();
        $notification->save();

        if ($notification->user_id) {
            $this->forgetUnreadCache((int) $notification->user_id);
        }

        return $notification->refresh();
    }

    public function markAllAsReadByReference(
        User|int $user,
        string $module,
        string $referenceType,
        int $referenceId
    ): int {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $moduleValue = trim($module);
        $referenceTypeValue = trim($referenceType);

        if ($userId <= 0 || $moduleValue === '' || $referenceTypeValue === '' || $referenceId <= 0) {
            return 0;
        }

        $updated = Notification::query()
            ->where('user_id', $userId)
            ->where('module', $moduleValue)
            ->where('reference_type', $referenceTypeValue)
            ->where('reference_id', $referenceId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($updated > 0) {
            $this->forgetUnreadCache($userId);
        }

        return $updated;
    }

    public function markAllAsReadForUser(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        if ($userId <= 0) {
            return 0;
        }

        $updated = Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($updated > 0) {
            $this->forgetUnreadCache($userId);
        }

        return $updated;
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteManyForUser(User|int $user, array $ids): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $uniqueIds = array_values(array_unique(array_filter(array_map(
            fn ($value) => (int) $value,
            $ids
        ), fn ($id) => $id > 0)));

        if ($userId <= 0 || $uniqueIds === []) {
            return 0;
        }

        $deleted = Notification::query()
            ->where('user_id', $userId)
            ->whereIn('id', $uniqueIds)
            ->delete();

        if ($deleted > 0) {
            $this->forgetUnreadCache($userId);
        }

        return $deleted;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function listForUser(
        User|int $user,
        int $limit = 50,
        ?string $module = null,
        ?bool $onlyUnread = null
    ): Collection {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $safeLimit = max(1, min(200, $limit));
        $moduleValue = $this->normalizeNullableString($module);

        $query = Notification::query()
            ->where('user_id', $userId)
            ->when($moduleValue !== null, fn (Builder $builder) => $builder->where('module', $moduleValue))
            ->when(
                $onlyUnread !== null,
                fn (Builder $builder) => $builder->where('is_read', $onlyUnread ? false : true)
            )
            ->latest('id')
            ->limit($safeLimit);

        return $query->get();
    }

    /**
     * @return array<string, int>
     */
    public function unreadCountByModule(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        if (app()->environment('testing')) {
            return $this->queryUnreadCountByModule($userId);
        }

        return Cache::remember(
            $this->unreadCacheKey($userId),
            now()->addSeconds(10),
            fn () => $this->queryUnreadCountByModule($userId)
        );
    }

    private function unreadCacheKey(int $userId): string
    {
        return "notifications:unread_by_module:{$userId}";
    }

    private function forgetUnreadCache(int $userId): void
    {
        Cache::forget($this->unreadCacheKey($userId));
    }

    /**
     * @param array<string, int> $byModule
     */
    public function totalUnread(array $byModule): int
    {
        return array_sum($byModule);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, int>
     */
    private function queryUnreadCountByModule(int $userId): array
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->selectRaw('module, COUNT(*) as total')
            ->groupBy('module')
            ->pluck('total', 'module')
            ->map(fn ($value) => (int) $value)
            ->toArray();
    }
}