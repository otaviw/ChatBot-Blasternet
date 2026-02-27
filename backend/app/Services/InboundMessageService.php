<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Bot\StatefulBotService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InboundMessageService
{
    public function __construct(
        private BotReplyService $botReply,
        private WhatsAppSendService $whatsAppSend,
        private StatefulBotService $statefulBot
    ) {}

    public function handleIncomingText(
        ?Company $company,
        string $from,
        string $text,
        array $inMeta = [],
        array $outMeta = [],
        bool $sendOutbound = true
    ): array {
        $normalizedFrom = $this->normalizePhone($from);
        $normalizedText = trim($text);

        if ($normalizedFrom === '' || $normalizedText === '') {
            throw new InvalidArgumentException('Phone e texto sao obrigatorios para processar mensagem.');
        }

        $conversation = Conversation::firstOrCreate(
            [
                'company_id' => $company?->id,
                'customer_phone' => $normalizedFrom,
            ],
            [
                'status' => 'open',
                'assigned_type' => 'unassigned',
                'handling_mode' => 'bot',
            ]
        );

        if ($conversation->status === 'closed') {
            $this->reopenClosedConversation($conversation);
            $conversation->save();
        }

        $isFirstInboundMessage = ! Message::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->exists();

        $inMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'text' => $normalizedText,
            'meta' => $inMeta,
        ]);

        if ($conversation->isManualMode()) {
            $conversation->status = 'in_progress';
            $conversation->save();

            return [
                'conversation' => $conversation,
                'in_message' => $inMessage,
                'out_message' => null,
                'reply' => null,
                'was_sent' => false,
                'auto_replied' => false,
            ];
        }

        $statefulResult = $this->statefulBot->handle(
            $company,
            $conversation,
            $normalizedText,
            $isFirstInboundMessage
        );

        $statefulHandled = (bool) ($statefulResult['handled'] ?? false);
        $reply = $statefulHandled
            ? trim((string) ($statefulResult['reply_text'] ?? ''))
            : $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);

        if ($reply === '') {
            $statefulHandled = false;
            $reply = $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);
        }

        [$outMessage, $updatedConversation] = DB::transaction(function () use (
            $conversation,
            $reply,
            $outMeta,
            $statefulHandled,
            $statefulResult
        ) {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $outMessage = Message::create([
                'conversation_id' => $lockedConversation->id,
                'direction' => 'out',
                'type' => 'bot',
                'text' => $reply,
                'meta' => $outMeta,
            ]);

            if ($statefulHandled) {
                $this->applyStatefulConversationUpdate($lockedConversation, $statefulResult);
            } else {
                $this->applyLegacyBotConversationUpdate($lockedConversation);
            }

            $lockedConversation->save();

            return [$outMessage, $lockedConversation];
        });

        $wasSent = $sendOutbound
            ? $this->whatsAppSend->sendText($company, $from, $reply)
            : false;

        return [
            'conversation' => $updatedConversation,
            'in_message' => $inMessage,
            'out_message' => $outMessage,
            'reply' => $reply,
            'was_sent' => $wasSent,
            'auto_replied' => true,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    private function reopenClosedConversation(Conversation $conversation): void
    {
        $conversation->status = 'open';
        $conversation->closed_at = null;
        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'bot';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    private function applyLegacyBotConversationUpdate(Conversation $conversation): void
    {
        $conversation->status = 'open';
        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'bot';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    private function applyStatefulConversationUpdate(Conversation $conversation, array $statefulResult): void
    {
        $shouldHandoff = (bool) ($statefulResult['should_handoff'] ?? false);

        if (! $shouldHandoff) {
            $conversation->status = 'open';
            $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? 'bot');
            $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? 'bot');
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

        $conversation->status = 'in_progress';
        $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? 'human');
        $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? 'unassigned');
        $conversation->assigned_id = $statefulResult['set_assigned_id'] ?? null;
        $conversation->current_area_id = $statefulResult['set_current_area_id'] ?? null;
        $conversation->assigned_user_id = null;
        $targetAreaName = is_array($handoffTarget)
            ? trim((string) ($handoffTarget['name'] ?? ''))
            : '';
        $conversation->assigned_area = $targetAreaName === '' ? null : $targetAreaName;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    private function applyBotStateFromResult(Conversation $conversation, array $statefulResult): void
    {
        if ((bool) ($statefulResult['clear_state'] ?? false)) {
            $this->clearBotState($conversation);

            return;
        }

        $newState = is_array($statefulResult['new_state'] ?? null)
            ? $statefulResult['new_state']
            : null;

        if (! $newState) {
            $this->clearBotState($conversation);

            return;
        }

        $conversation->bot_flow = $newState['flow'] ?? null;
        $conversation->bot_step = $newState['step'] ?? null;
        $conversation->bot_context = is_array($newState['context'] ?? null)
            ? $newState['context']
            : [];
        $conversation->bot_last_interaction_at = now();
    }

    private function clearBotState(Conversation $conversation): void
    {
        $conversation->bot_flow = null;
        $conversation->bot_step = null;
        $conversation->bot_context = null;
        $conversation->bot_last_interaction_at = null;
    }
}
