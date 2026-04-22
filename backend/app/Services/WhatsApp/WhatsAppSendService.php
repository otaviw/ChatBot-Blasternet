<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class WhatsAppSendService
{
    public function __construct(
        private TextMessageHandler $text,
        private MediaMessageHandler $media,
        private TemplateMessageHandler $template,
        private InteractiveMessageHandler $interactive,
    ) {}

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendText(?Company $company, string $toPhone, string $text): array
    {
        return $this->text->send($company, $toPhone, $text);
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendImage(?Company $company, string $toPhone, string $imageUrl, ?string $caption = null): array
    {
        return $this->media->sendImage($company, $toPhone, $imageUrl, $caption);
    }

    /** @return array{id: string}|null */
    public function uploadMedia(?Company $company, string $binaryData, string $mimeType, ?string $filename = null): ?array
    {
        return $this->media->uploadMedia($company, $binaryData, $mimeType, $filename);
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendMedia(?Company $company, string $toPhone, string $mediaId, string $type, ?string $caption = null): array
    {
        return $this->media->sendMedia($company, $toPhone, $mediaId, $type, $caption);
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendMediaFile(
        ?Company $company,
        string $toPhone,
        string $filePath,
        string $mimeType,
        string $type,
        ?string $caption = null,
        ?string $filename = null
    ): array {
        return $this->media->sendMediaFile($company, $toPhone, $filePath, $mimeType, $type, $caption, $filename);
    }

    /**
     * @param  array<int, array{id:string, title:string}>  $buttons
     * @param  array{header_text?:string, footer_text?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendInteractiveButtons(string $phone, string $bodyText, array $buttons, array $options = [], ?Company $company = null): array
    {
        return $this->interactive->sendButtons($phone, $bodyText, $buttons, $options, $company);
    }

    /**
     * @param  array<int, array{id:string, title:string, description?:string}>  $rows
     * @param  array{header_text?:string, footer_text?:string, action_label?:string}  $options
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendInteractiveList(string $phone, string $bodyText, array $rows, array $options = [], ?Company $company = null): array
    {
        return $this->interactive->sendList($phone, $bodyText, $rows, $options, $company);
    }

    public function isConversationOpen(?Conversation $conversation): bool
    {
        if (! $conversation || ! $conversation->last_user_message_at) {
            return false;
        }

        return $conversation->last_user_message_at->gt(now()->subHours(24));
    }

    /**
     * @param  string[]  $variables
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendTemplateMessage(
        ?Company $company,
        string $toPhone,
        string $templateName,
        array $variables = [],
        string $languageCode = 'pt_BR'
    ): array {
        return $this->template->send($company, $toPhone, $templateName, $variables, $languageCode);
    }

    /**
     * @return array{ok:bool,templates:array<int,array{name:string,status:string,language:string,category:string,components:array}>,error:mixed}
     */
    public function fetchTemplates(?Company $company): array
    {
        return $this->template->fetchTemplates($company);
    }

    /**
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
            'company_id'           => $company?->id,
            'to'                   => $toPhone,
            'template'             => $templateName,
            'last_user_message_at' => $conversation?->last_user_message_at?->toIso8601String(),
        ]);

        return $this->sendTemplateMessage($company, $toPhone, $templateName, $templateVariables);
    }

    /** @return array{binary:string,mime_type:?string,size_bytes:?int}|null */
    public function downloadInboundImage(?Company $company, string $mediaId): ?array
    {
        return $this->media->downloadInboundImage($company, $mediaId);
    }

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
}
