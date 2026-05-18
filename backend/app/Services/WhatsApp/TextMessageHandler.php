<?php

declare(strict_types=1);


namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsApp\Concerns\WhatsAppApiHelpers;
use App\Support\LogSanitizer;
use App\Support\Enums\MessageType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMessageHandler
{
    use WhatsAppApiHelpers;

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function send(?Company $company, string $toPhone, string $text): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de texto ignorado: destinatario inválido.', [
                'company_id'  => $company?->id,
                'to_original' => LogSanitizer::maskPhone($toPhone),
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio simulado (sem token/number_id).', [
                'company_id'      => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? LogSanitizer::maskToken($phoneNumberId) : null,
                'to'              => LogSanitizer::maskPhone($normalizedTo),
                'text_preview'    => LogSanitizer::truncateText($text, 60),
                'text_length'     => mb_strlen($text),
            ]);

            return $this->successResult(null, ['simulated' => true]);
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => MessageType::TEXT->value,
            'text'              => ['body' => $text],
        ];

        $this->logRequestDiagnostics($company, MessageType::TEXT->value, $url, $phoneNumberId, $normalizedTo);

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException $exception) {
            $error = $this->normalizeMetaConnectionError($exception);
            Log::warning('WhatsApp API falha de conexao ao enviar texto.', [
                'company_id' => $company?->id,
                'to' => LogSanitizer::maskPhone($normalizedTo),
                'error_code' => $error['code'],
                'retryable' => $error['retryable'],
            ]);

            return $this->failedResult($error);
        }

        $this->logResponseDiagnostics(MessageType::TEXT->value, $response);
        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            $error = $this->normalizeMetaApiError($response, $responseJson);
            Log::warning('WhatsApp API erro ao enviar.', [
                'status' => $response->status(),
                'error'  => $error['code'],
                'retryable' => $error['retryable'],
            ]);

            return $this->failedResult($error, $responseJson);
        }

        return $this->successResult($graphMessageId, $responseJson);
    }
}
