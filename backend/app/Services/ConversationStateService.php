<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Conversation;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;

class ConversationStateService
{
    public function applyLegacyUpdate(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::OPEN;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->clearBotState();
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    public function applyStatefulUpdate(Conversation $conversation, array $statefulResult): void
    {
        $shouldHandoff = (bool) ($statefulResult['should_handoff'] ?? false);

        if (! $shouldHandoff) {
            $conversation->status = ConversationStatus::OPEN;
            $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? ConversationHandlingMode::BOT);
            $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? ConversationAssignedType::BOT);
            $conversation->assigned_id = $statefulResult['set_assigned_id'] ?? null;
            $conversation->current_area_id = $statefulResult['set_current_area_id'] ?? null;
            $conversation->assigned_user_id = null;
            $conversation->assigned_area = null;
            $conversation->assumed_at = null;
            $this->applyBotStateFromResult($conversation, $statefulResult);

            return;
        }

        $handoffTarget = is_array($statefulResult['handoff_target'] ?? null)
            ? $statefulResult['handoff_target']
            : null;

        $conversation->status = ConversationStatus::IN_PROGRESS;
        $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? ConversationHandlingMode::HUMAN);
        $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? ConversationAssignedType::UNASSIGNED);
        $conversation->assigned_id = $statefulResult['set_assigned_id'] ?? null;
        $conversation->current_area_id = $statefulResult['set_current_area_id'] ?? null;
        $conversation->assigned_user_id = null;
        $targetAreaName = is_array($handoffTarget)
            ? trim((string) ($handoffTarget['name'] ?? ''))
            : '';
        $conversation->assigned_area = $targetAreaName === '' ? null : $targetAreaName;
        $conversation->assumed_at = null;
        $conversation->clearBotState();
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    private function applyBotStateFromResult(Conversation $conversation, array $statefulResult): void
    {
        if ((bool) ($statefulResult['clear_state'] ?? false)) {
            $conversation->clearBotState();

            return;
        }

        $newState = is_array($statefulResult['new_state'] ?? null)
            ? $statefulResult['new_state']
            : null;

        if (! $newState) {
            $conversation->clearBotState();

            return;
        }

        $conversation->bot_flow = $newState['flow'] ?? null;
        $conversation->bot_step = $newState['step'] ?? null;
        $conversation->bot_context = is_array($newState['context'] ?? null)
            ? $newState['context']
            : [];
        $conversation->bot_last_interaction_at = now();
    }
}
