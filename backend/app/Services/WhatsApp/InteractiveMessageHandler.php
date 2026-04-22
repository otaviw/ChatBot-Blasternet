<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsApp\Concerns\WhatsAppApiHelpers;
use App\Support\Enums\MessageType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InteractiveMessageHandler
{
    use WhatsAppApiHelpers;

    /**
     * @param  array<int, array{id:string, title:string}>  $buttons
     * @param  array{header_text?:string, footer_text?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendButtons(string $phone, string $bodyText, array $buttons, array $options = [], ?Company $company = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($phone);

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de botões interativos ignorado: destinatario inválido.', [
                'company_id'  => $company?->id,
                'to_original' => $phone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de botões interativos simulado (sem token/number_id).', [
                'company_id'      => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to'              => $normalizedTo,
                'body_text'       => $bodyText,
            ]);

            return $this->successResult(null, ['simulated' => true]);
        }

        $mappedButtons = array_map(fn ($btn) => [
            'type'  => 'reply',
            'reply' => [
                'id'    => (string) ($btn['id'] ?? ''),
                'title' => mb_substr((string) ($btn['title'] ?? ''), 0, 20),
            ],
        ], $buttons);

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $bodyText],
            'action' => ['buttons' => $mappedButtons],
        ];

        $headerText = trim((string) ($options['header_text'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => $headerText];
        }

        $footerText = trim((string) ($options['footer_text'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = ['text' => $footerText];
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $normalizedTo,
            'type'              => MessageType::INTERACTIVE->value,
            'interactive'       => $interactive,
        ];

        $this->logRequestDiagnostics($company, 'interactive_buttons', $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('interactive_buttons', $response);
        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar botões interativos.', [
                'status' => $response->status(),
                'body'   => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @param  array<int, array{id:string, title:string, description?:string}>  $rows
     * @param  array{header_text?:string, footer_text?:string, action_label?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendList(string $phone, string $bodyText, array $rows, array $options = [], ?Company $company = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($phone);

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de lista interativa ignorado: destinatario inválido.', [
                'company_id'  => $company?->id,
                'to_original' => $phone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de lista interativa simulado (sem token/number_id).', [
                'company_id'      => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to'              => $normalizedTo,
                'body_text'       => $bodyText,
            ]);

            return $this->successResult(null, ['simulated' => true]);
        }

        $actionLabel = trim((string) ($options['action_label'] ?? ''));
        if ($actionLabel === '') {
            $actionLabel = 'Ver opções';
        }

        $mappedRows = array_map(fn ($row) => [
            'id'          => (string) ($row['id'] ?? ''),
            'title'       => mb_substr((string) ($row['title'] ?? ''), 0, 24),
            'description' => (string) ($row['description'] ?? ''),
        ], $rows);

        $interactive = [
            'type' => 'list',
            'body' => ['text' => $bodyText],
            'action' => [
                'button'   => $actionLabel,
                'sections' => [
                    [
                        'title' => 'Opções',
                        'rows'  => $mappedRows,
                    ],
                ],
            ],
        ];

        $headerText = trim((string) ($options['header_text'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => $headerText];
        }

        $footerText = trim((string) ($options['footer_text'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = ['text' => $footerText];
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $normalizedTo,
            'type'              => MessageType::INTERACTIVE->value,
            'interactive'       => $interactive,
        ];

        $this->logRequestDiagnostics($company, 'interactive_list', $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('interactive_list', $response);
        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar lista interativa.', [
                'status' => $response->status(),
                'body'   => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }
}
