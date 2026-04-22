<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsApp\Concerns\WhatsAppApiHelpers;
use App\Support\Enums\MessageType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TemplateMessageHandler
{
    use WhatsAppApiHelpers;

    /**
     * @param  string[]  $variables
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function send(
        ?Company $company,
        string $toPhone,
        string $templateName,
        array $variables = [],
        string $languageCode = 'pt_BR'
    ): array {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);

        if ($normalizedTo === '') {
            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de template simulado (sem token/number_id).', [
                'company_id'    => $company?->id,
                'to'            => $normalizedTo,
                'template_name' => $templateName,
            ]);

            return $this->successResult(null, ['simulated' => true]);
        }

        $components = [];
        if (! empty($variables)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $variables),
            ];
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => MessageType::TEMPLATE->value,
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $languageCode],
                'components' => $components,
            ],
        ];

        Log::info('WhatsApp API template request.', [
            'company_id'    => $company?->id,
            'template_name' => $templateName,
            'to'            => $normalizedTo,
        ]);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar template.', [
                'template_name' => $templateName,
                'status'        => $response->status(),
                'body'          => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @return array{ok:bool,templates:array<int,array{name:string,status:string,language:string,category:string,components:array}>,error:mixed}
     */
    public function fetchTemplates(?Company $company): array
    {
        $accessToken = $this->resolveAccessToken($company);
        if ($accessToken === '') {
            return ['ok' => false, 'templates' => [], 'error' => 'sem_access_token'];
        }

        $wabaId = $this->resolveWabaId($company, $accessToken);
        if ($wabaId === '') {
            return ['ok' => false, 'templates' => [], 'error' => 'sem_waba_id'];
        }

        $baseUrl  = rtrim((string) config('whatsapp.api_url'), '/');
        $url      = "{$baseUrl}/{$wabaId}/message_templates";

        $response = Http::withToken($accessToken)
            ->get($url, [
                'fields' => 'name,status,language,category,components',
                'limit'  => 200,
            ]);

        if (! $response->successful()) {
            Log::warning('WhatsApp: falha ao buscar templates da Meta.', [
                'waba_id' => $wabaId,
                'status'  => $response->status(),
                'body'    => $response->json(),
            ]);

            return [
                'ok'        => false,
                'templates' => [],
                'error'     => $response->json('error') ?? $response->body(),
            ];
        }

        $raw = $response->json('data') ?? [];

        $templates = collect($raw)
            ->filter(fn ($t) => ($t['status'] ?? '') === 'APPROVED')
            ->map(fn ($t) => [
                'name'       => (string) ($t['name'] ?? ''),
                'status'     => (string) ($t['status'] ?? ''),
                'language'   => (string) ($t['language'] ?? ''),
                'category'   => (string) ($t['category'] ?? ''),
                'components' => is_array($t['components'] ?? null) ? $t['components'] : [],
            ])
            ->values()
            ->all();

        return ['ok' => true, 'templates' => $templates, 'error' => null];
    }

    private function resolveWabaId(?Company $company, string $accessToken): string
    {
        $saved = trim((string) ($company?->meta_waba_id ?? ''));
        if ($saved !== '') {
            return $saved;
        }

        $fromEnv = trim((string) config('whatsapp.waba_id', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $phoneNumberId = $this->resolvePhoneNumberId($company);
        if ($phoneNumberId === '') {
            return '';
        }

        $baseUrl  = rtrim((string) config('whatsapp.api_url'), '/');
        $response = Http::withToken($accessToken)
            ->get("{$baseUrl}/{$phoneNumberId}", [
                'fields' => 'whatsapp_business_account',
            ]);

        if (! $response->successful()) {
            Log::warning('WhatsApp: falha ao descobrir WABA ID via phone_number_id.', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return '';
        }

        $discoveredWabaId = trim((string) ($response->json('whatsapp_business_account.id') ?? ''));

        if ($discoveredWabaId !== '' && $company?->id) {
            $company->meta_waba_id = $discoveredWabaId;
            $company->saveQuietly();

            Log::info('WhatsApp: WABA ID auto-descoberto e salvo.', [
                'company_id' => $company->id,
                'waba_id'    => $discoveredWabaId,
            ]);
        }

        return $discoveredWabaId;
    }
}
