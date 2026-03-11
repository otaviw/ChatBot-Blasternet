<?php

namespace App\Services;

use App\Models\Message;

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
            $message->whatsapp_message_id = $whatsappMessageId !== '' ? $whatsappMessageId : null;
            $message->delivery_status = 'sent';
            $message->sent_at = $message->sent_at ?: now();
            $message->status_error = null;
            $message->status_meta = [
                'source' => $source,
                'send_result' => $response,
            ];
            $message->save();

            return;
        }

        $message->delivery_status = 'failed';
        $message->failed_at = $message->failed_at ?: now();
        $message->status_error = $this->stringifyError($sendResult['error'] ?? null);
        $message->status_meta = [
            'source' => $source,
            'send_result' => $response,
            'error' => $sendResult['error'] ?? null,
        ];
        $message->save();
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
