<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use App\Support\ConversationHandlingMode;
use App\Support\Enums\ConversationStatus;
use App\Support\ConversationAssignedType;

class ConversationInactivityService
{
    private const DEFAULT_INACTIVITY_CLOSE_HOURS = 24;
    private const MIN_INACTIVITY_CLOSE_HOURS = 1;
    private const MAX_INACTIVITY_CLOSE_HOURS = 720;

    public function closeInactiveConversations(?int $companyId = null): int
    {
        $targets = $this->resolveCompanyTargets($companyId);
        if ($targets === []) {
            return 0;
        }

        $totalClosed = 0;
        $closedAt = now();

        foreach ($targets as $target) {
            $hours = $target['hours'];
            $cutoff = $closedAt->copy()->subHours($hours);

            $closedCount = Conversation::query()
                ->where('company_id', $target['company_id'])
                ->whereIn('status', [ConversationStatus::OPEN->value, ConversationStatus::IN_PROGRESS->value])
                ->whereRaw(
                    'COALESCE((SELECT created_at FROM messages WHERE messages.conversation_id = conversations.id ORDER BY id DESC LIMIT 1), conversations.created_at) < ?',
                    [$cutoff]
                )
                ->update([
                    'status'        => ConversationStatus::CLOSED->value,
                    'handling_mode' => ConversationHandlingMode::BOT,
                    'assigned_type' => ConversationAssignedType::UNASSIGNED,
                    'assigned_id' => null,
                    'current_area_id' => null,
                    'assigned_user_id' => null,
                    'assigned_area' => null,
                    'assumed_at' => null,
                    'closed_at' => $closedAt,
                ]);

            $totalClosed += $closedCount;
        }

        return $totalClosed;
    }

    /**
     * @return array<int, array{company_id:int, hours:int}>
     */
    private function resolveCompanyTargets(?int $companyId = null): array
    {
        $query = Company::query()
            ->select('id')
            ->with('botSetting:id,company_id,inactivity_close_hours');

        if ($companyId !== null && $companyId > 0) {
            $query->whereKey($companyId);
        }

        return $query->get()
            ->map(function (Company $company): array {
                return [
                    'company_id' => (int) $company->id,
                    'hours' => $this->normalizeHours($company->botSetting?->inactivity_close_hours),
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeHours(mixed $value): int
    {
        $hours = (int) $value;
        if ($hours < self::MIN_INACTIVITY_CLOSE_HOURS || $hours > self::MAX_INACTIVITY_CLOSE_HOURS) {
            return self::DEFAULT_INACTIVITY_CLOSE_HOURS;
        }

        return $hours;
    }
}

