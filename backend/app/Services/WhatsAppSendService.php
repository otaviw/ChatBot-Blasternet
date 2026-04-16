<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSendService
{
    /**
     * Envia texto para um numero WhatsApp.
     * Prioriza credenciais da company e usa env como fallback single-tenant.
     *
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    public function sendText(?Company $company, string $toPhone, string $text): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de texto ignorado: destinatario inválido.', [
                'company_id' => $company?->id,
                'to_original' => $toPhone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio simulado (sem token/number_id).', [
                'company_id' => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to' => $normalizedTo,
                'text' => $text,
            ]);

            return $this->successResult(null, [
                'simulated' => true,
            ]);
        }

        $url = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        $this->logRequestDiagnostics($company, 'text', $url, $phoneNumberId, $normalizedTo);

        /** @var Response $response */
        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('text', $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar.', [
                'status' => $response->status(),
                'body' => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    public function sendImage(?Company $company, string $toPhone, string $imageUrl, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);
        $normalizedUrl = trim($imageUrl);
        if ($normalizedUrl !== '' && str_starts_with($normalizedUrl, '/')) {
            $normalizedUrl = rtrim((string) config('app.url'), '/') . $normalizedUrl;
        }

        if ($normalizedUrl === '') {
            Log::warning('WhatsApp envio de imagem ignorado: URL vazia.', [
                'to' => $toPhone,
            ]);

            return $this->failedResult('imagem_url_vazia');
        }

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de imagem ignorado: destinatario inválido.', [
                'company_id' => $company?->id,
                'to_original' => $toPhone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de imagem simulado (sem token/number_id).', [
                'company_id' => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to' => $normalizedTo,
                'image_url' => $normalizedUrl,
                'caption' => $caption,
            ]);

            return $this->successResult(null, [
                'simulated' => true,
            ]);
        }

        $url = $this->messagesUrl($phoneNumberId);
        $image = ['link' => $normalizedUrl];
        $captionValue = trim((string) $caption);
        if ($captionValue !== '') {
            $image['caption'] = $captionValue;
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => 'image',
            'image' => $image,
        ];

        $this->logRequestDiagnostics($company, 'image', $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('image', $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar imagem.', [
                'status' => $response->status(),
                'body' => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * 1. Upload arquivo local pra Meta (obrigatório pra mídia outbound).
     * @return array{id: string}|null
     */
    public function uploadMedia(?Company $company, string $binaryData, string $mimeType, ?string $filename = null): ?array
    {
        $accessToken = $this->resolveAccessToken($company);
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        if (!$accessToken || !$phoneNumberId) {
            Log::warning('Upload mídia falhou: sem token/phone_id');
            return null;
        }

        $url = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";

        $response = Http::withToken($accessToken)
            ->attach('filedata', $binaryData, $filename ?: 'file', $mimeType)  // Meta usa 'filedata'
            ->post($url);

        if (!$response->successful()) {
            Log::warning('Upload mídia falhou', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $id = $response->json('id');
        Log::info('Upload mídia sucesso', ['media_id' => $id]);
        return ['id' => $id];
    }

    /**
     * 2. Envia qualquer mídia (image/video/audio/document) via media_id.
     */
    public function sendMedia(?Company $company, string $toPhone, string $mediaId, string $type, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if (!$phoneNumberId || !$accessToken || !$normalizedTo) {
            return $this->failedResult('config_invalida');
        }

        $url = $this->messagesUrl($phoneNumberId);
        $media = ['id' => $mediaId];
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => $type,  // 'image', 'video', 'audio', 'document'
            $type => $media,
        ];
        if ($caption) $body[$type]['caption'] = $caption;

        $this->logRequestDiagnostics($company, $type, $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics($type, $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id') ?? null);

        if (!$response->successful()) {
            Log::warning('Send mídia falhou', ['status' => $response->status(), 'body' => $responseJson]);
            return $this->failedResult($response->json('error') ?? $responseJson, $responseJson);
        }

        Log::info('Send mídia sucesso', ['whatsapp_message_id' => $graphMessageId]);
        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * Envia mídia de forma inteligente: 
     * - Se tem token/phone_id → upload Meta real
     * - Se não → simulado local (ok=true)
     */
    public function sendMediaFile(?Company $company, string $toPhone, string $filePath, string $mimeType, string $type, ?string $caption = null, ?string $filename = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if (!$phoneNumberId || !$accessToken || !$normalizedTo) {
            // ← LOCAL: Sem config Meta → simula (ok=true)
            Log::info('Envio local simulado (sem config Meta).', [
                'file' => basename($filePath),
                'type' => $type,
            ]);
            return [
                'ok' => true,  // ← Simula como sucesso pra DB
                'whatsapp_message_id' => null,
                'status' => 'sent',
                'error' => null,
                'response' => ['simulated' => true, 'type' => $type],
            ];
        }

        // ← META: Tem config → upload real
        $fileExists = file_exists($filePath);
        $fileSize   = $fileExists ? (int) filesize($filePath) : 0;

        Log::info('sendMediaFile: verificando arquivo.', [
            'path'       => $filePath,
            'exists'     => $fileExists,
            'size_bytes' => $fileSize,
            'mime'       => $mimeType,
            'type'       => $type,
        ]);

        if (! $fileExists || $fileSize === 0) {
            Log::error('sendMediaFile: arquivo não encontrado ou vazio.', ['path' => $filePath]);

            return $this->failedResult('arquivo_nao_encontrado');
        }

        $fileBinary = file_get_contents($filePath);
        if ($fileBinary === false || $fileBinary === '') {
            Log::error('sendMediaFile: leitura do arquivo falhou.', ['path' => $filePath]);

            return $this->failedResult('arquivo_nao_lido');
        }

        $uploadUrl = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";

        // A API da Meta exige multipart com os campos: file, messaging_product, type
        $uploadResponse = Http::withToken($accessToken)
            ->attach('file', $fileBinary, $filename ?: basename($filePath), ['Content-Type' => $mimeType])
            ->post($uploadUrl, [
                'messaging_product' => 'whatsapp',
                'type'              => $mimeType,
            ]);

        if (!$uploadResponse->successful()) {
            return $this->failedResult('upload_falhou', $this->responseJson($uploadResponse));
        }

        $mediaId = $uploadResponse->json('id');
        if (!$mediaId) {
            return $this->failedResult('media_id_invalido');
        }

        $sendUrl = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => $type,
            $type => ['id' => $mediaId],
        ];
        if ($caption) $body[$type]['caption'] = trim((string) $caption);

        $sendResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($sendUrl, $body);

        if (!$sendResponse->successful()) {
            return $this->failedResult('envio_falhou', $this->responseJson($sendResponse));
        }

        $graphMessageId = $this->normalizeGraphMessageId($sendResponse->json('messages.0.id') ?? null);
        return $this->successResult($graphMessageId, $this->responseJson($sendResponse));
    }

    /**
     * Envia mensagem interativa com botões de resposta rápida (máximo 3 botões).
     *
     * @param  array<int, array{id:string, title:string}>  $buttons
     * @param  array{header_text?:string, footer_text?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendInteractiveButtons(string $phone, string $bodyText, array $buttons, array $options = [], ?Company $company = null): array
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
                'company_id'     => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to'             => $normalizedTo,
                'body_text'      => $bodyText,
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
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        $this->logRequestDiagnostics($company, 'interactive_buttons', $url, $phoneNumberId, $normalizedTo);

        /** @var Response $response */
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
     * Envia mensagem interativa com lista de seleção (máximo 10 itens).
     *
     * @param  array<int, array{id:string, title:string, description?:string}>  $rows
     * @param  array{header_text?:string, footer_text?:string, action_label?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendInteractiveList(string $phone, string $bodyText, array $rows, array $options = [], ?Company $company = null): array
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
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        $this->logRequestDiagnostics($company, 'interactive_list', $url, $phoneNumberId, $normalizedTo);

        /** @var Response $response */
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

    /**
     * Verifica se a janela de 24h da conversa ainda está aberta.
     * Retorna true se o usuário enviou mensagem nas últimas 24h.
     */
    public function isConversationOpen(?Conversation $conversation): bool
    {
        if (! $conversation || ! $conversation->last_user_message_at) {
            return false;
        }

        return $conversation->last_user_message_at->gt(now()->subHours(24));
    }

    /**
     * Envia template de WhatsApp (usado para iniciar/reabrir conversas).
     *
     * @param  string[]  $variables  Parâmetros de texto para o body do template
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendTemplateMessage(
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
            'type'              => 'template',
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

        /** @var Response $response */
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
     * Envia mensagem de forma inteligente:
     * - Dentro da janela de 24h → mensagem normal
     * - Fora da janela ou conversa nova → template iniciar_conversa
     * - Se envio normal falhar por janela expirada → fallback automático para template
     *
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendSmartMessage(
        ?Company $company,
        string $toPhone,
        string $message,
        ?Conversation $conversation = null,
        string $templateName = 'iniciar_conversa',
        array $templateVariables = ['Cliente', 'seu atendimento']
    ): array {
        if ($this->isConversationOpen($conversation)) {
            $result = $this->sendText($company, $toPhone, $message);

            // Fallback automático se a API retornar erro de janela expirada (código 131047)
            if (! $result['ok'] && $this->isWindowExpiredError($result['error'])) {
                Log::info('WhatsApp janela 24h expirada — fallback para template.', [
                    'company_id' => $company?->id,
                    'to'         => $toPhone,
                    'template'   => $templateName,
                ]);

                return $this->sendTemplateMessage($company, $toPhone, $templateName, $templateVariables);
            }

            return $result;
        }

        Log::info('WhatsApp conversa fora da janela 24h — enviando template.', [
            'company_id'             => $company?->id,
            'to'                     => $toPhone,
            'template'               => $templateName,
            'last_user_message_at'   => $conversation?->last_user_message_até->toIso8601String(),
        ]);

        return $this->sendTemplateMessage($company, $toPhone, $templateName, $templateVariables);
    }

    /**
     * Verifica se o erro retornado pela API da Meta indica janela de 24h expirada.
     * Código Meta: 131047
     */
    private function isWindowExpiredError(mixed $error): bool
    {
        if (is_array($error)) {
            $code = (int) ($error['code'] ?? 0);
            if ($code === 131047) {
                return true;
            }
        }

        if (is_string($error) && str_contains($error, '131047')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{binary:string,mime_type:?string,size_bytes:?int}|null
     */
    public function downloadInboundImage(?Company $company, string $mediaId): ?array
    {
        $accessToken = $this->resolveAccessToken($company);
        $normalizedMediaId = trim($mediaId);
        if ($normalizedMediaId === '' || $accessToken === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('whatsapp.api_url'), '/');
        $metadataUrl = $baseUrl . '/' . $normalizedMediaId;

        $metadataResponse = Http::withToken($accessToken)->get($metadataUrl);
        if (! $metadataResponse->successful()) {
            Log::warning('Falha ao obter metadata de media no WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status' => $metadataResponse->status(),
            ]);

            return null;
        }

        $metadata = $metadataResponse->json();
        $downloadUrl = trim((string) ($metadata['url'] ?? ''));
        if ($downloadUrl === '') {
            return null;
        }

        $mediaResponse = Http::withToken($accessToken)->get($downloadUrl);
        if (! $mediaResponse->successful()) {
            Log::warning('Falha ao baixar media do WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status' => $mediaResponse->status(),
            ]);

            return null;
        }

        return [
            'binary' => (string) $mediaResponse->body(),
            'mime_type' => $metadata['mime_type'] ?? $mediaResponse->header('Content-Type'),
            'size_bytes' => isset($metadata['file_size']) ? (int) $metadata['file_size'] : strlen((string) $mediaResponse->body()),
        ];
    }

    /**
     * Busca templates aprovados da Meta API para a empresa.
     *
     * Estratégia de resolução do WABA ID:
     *  1. Usa meta_waba_id salvo na empresa
     *  2. Se não tiver, tenta descobrir via phone_number_id (chamada extra à API)
     *  3. Sem WABA ID → retorna lista vazia
     *
     * @return array{
     *   ok: bool,
     *   templates: array<int, array{name:string,status:string,language:string,category:string,components:array}>,
     *   error: mixed
     * }
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

    /**
     * Resolve o WABA ID: usa o salvo na empresa ou descobre via phone_number_id.
     */
    private function resolveWabaId(?Company $company, string $accessToken): string
    {
        $saved = trim((string) ($company?->meta_waba_id ?? ''));
        if ($saved !== '') {
            return $saved;
        }

        // Fallback: env
        $fromEnv = trim((string) config('whatsapp.waba_id', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        // Auto-descoberta: pergunta à Meta qual é o WABA do phone_number_id
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
            // Persiste para evitar a chamada extra nas próximas vezes
            $company->meta_waba_id = $discoveredWabaId;
            $company->saveQuietly();

            Log::info('WhatsApp: WABA ID auto-descoberto e salvo.', [
                'company_id' => $company->id,
                'waba_id'    => $discoveredWabaId,
            ]);
        }

        return $discoveredWabaId;
    }

    private function resolvePhoneNumberId(?Company $company): string
    {
        $companyPhoneNumberId = trim((string) ($company?->meta_phone_number_id ?? ''));
        if ($companyPhoneNumberId !== '') {
            return $companyPhoneNumberId;
        }

        if ($company?->id) {
            Log::warning('WhatsApp envio com fallback de phone_number_id do env.', [
                'company_id' => $company->id,
            ]);
        }

        return trim((string) config('whatsapp.phone_number_id', ''));
    }

    private function resolveAccessToken(?Company $company): string
    {
        $companyAccessToken = trim((string) ($company?->meta_access_token ?? ''));
        if ($companyAccessToken !== '') {
            return $companyAccessToken;
        }

        return trim((string) config('whatsapp.access_token', ''));
    }

    private function normalizeRecipient(string $to): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $to);

        return trim((string) $normalized);
    }

    private function messagesUrl(string $phoneNumberId): string
    {
        return rtrim((string) config('whatsapp.api_url'), '/') . '/' . $phoneNumberId . '/messages';
    }

    private function logRequestDiagnostics(
        ?Company $company,
        string $type,
        string $url,
        string $phoneNumberId,
        string $to
    ): void {
        Log::info('WhatsApp API request diagnostico.', [
            'company_id' => $company?->id,
            'type' => $type,
            'url' => $url,
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'to_length' => strlen($to),
        ]);
    }

    private function logResponseDiagnostics(string $type, Response $response): void
    {
        $payload = [
            'type' => $type,
            'status' => $response->status(),
            'success' => $response->successful(),
            'graph_message_id' => $response->json('messages.0.id'),
            'error' => $response->json('error'),
        ];

        if ($response->successful()) {
            Log::info('WhatsApp API response diagnostico.', $payload);

            return;
        }

        Log::warning('WhatsApp API response diagnostico com erro.', $payload);
    }

    /**
     * @return array<mixed>|null
     */
    private function responseJson(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function normalizeGraphMessageId(mixed $value): ?string
    {
        $id = trim((string) $value);

        return $id !== '' ? $id : null;
    }

    /**
     * @param  array<mixed>|null  $response
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    private function successResult(?string $whatsappMessageId, ?array $response = null): array
    {
        return [
            'ok' => true,
            'whatsapp_message_id' => $whatsappMessageId,
            'status' => 'sent',
            'error' => null,
            'response' => $response,
        ];
    }

    /**
     * @param  array<mixed>|null  $response
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    private function failedResult(mixed $error, ?array $response = null): array
    {
        return [
            'ok' => false,
            'whatsapp_message_id' => null,
            'status' => 'failed',
            'error' => $error,
            'response' => $response,
        ];
    }
}
