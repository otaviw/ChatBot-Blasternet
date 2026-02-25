<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use InvalidArgumentException;

class InboundMessageService
{
    public function __construct(
        private BotReplyService $botReply,
        private WhatsAppSendService $whatsAppSend
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
            $conversation->status = 'open';
            $conversation->closed_at = null;
            $conversation->handling_mode = 'bot';
            $conversation->assigned_type = 'bot';
            $conversation->assigned_id = null;
            $conversation->current_area_id = null;
            $conversation->assigned_user_id = null;
            $conversation->assigned_area = null;
            $conversation->assumed_at = null;
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

        $reply = $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);

        $outMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'bot',
            'text' => $reply,
            'meta' => $outMeta,
        ]);

        $wasSent = $sendOutbound
            ? $this->whatsAppSend->sendText($company, $from, $reply)
            : false;

        $conversation->status = 'open';
        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'bot';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->save();

        return [
            'conversation' => $conversation,
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
}
