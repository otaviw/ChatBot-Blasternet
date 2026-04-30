<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Message;
use App\Support\MessageDeliveryStatus;
use Illuminate\Support\Facades\Log;

class MessageDeliveryStatusService
{
    /**
     * @param  array<string, mixed>  $sendResult
     */
    public function applySendResult(Message $message, array $sendResult, string $source = 'send_service'): void
    {
        $isOk = (bool) ($sendResult['ok'] ?? false);
        $response = is_array($sendResult['response'] ?? null) ? $sendResult['response'] : null;
        $whatsappMessageId = trim((string) ($sendResult['whatsapp_message_id'] ?? ''));

        if ($isOk) {
            $message->whatsapp_message_id = $this->uniqueWhatsAppMessageIdFor($message, $whatsappMessageId);
            $message->delivery_status = MessageDeliveryStatus::SENT;
            $message->sent_at = $message->sent_at ?: now();
            $message->status_error = null;
            $message->status_meta = [
                'source' => $source,
                'send_result' => $response,
            ];
            $message->save();

            return;
        }

        $message->delivery_status = MessageDeliveryStatus::FAILED;
        $message->failed_at = $message->failed_at ?: now();
        $message->status_error = $this->stringifyError($sendResult['error'] ?? null);
        $message->status_meta = [
            'source' => $source,
            'send_result' => $response,
            'error' => $sendResult['error'] ?? null,
        ];
        $message->save();
    }

    private function uniqueWhatsAppMessageIdFor(Message $message, string $whatsappMessageId): ?string
    {
        if ($whatsappMessageId === '') {
            return null;
        }

        $existsForAnotherMessage = Message::query()
            ->where('whatsapp_message_id', $whatsappMessageId)
            ->whereKeyNot($message->id)
            ->exists();

        if (! $existsForAnotherMessage) {
            return $whatsappMessageId;
        }

        Log::warning('WhatsApp send result retornou message id duplicado; mensagem marcada como enviada sem vincular wamid.', [
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'whatsapp_message_id' => $whatsappMessageId,
        ]);

        return null;
    }

    private function stringifyError(mixed $error): ?string
    {
        if ($error === null) {
            return null;
        }

        if (is_string($error)) {
            $trimmed = trim($error);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_scalar($error)) {
            return (string) $error;
        }

        $encoded = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : 'whatsapp_send_error';
    }
}
