<?php

namespace App\Actions\Company\Conversation;

use App\Actions\Conversation\UpdateConversationStatusAction;
use App\Data\ActionResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuditService;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\MessageDeliveryStatusService;
use App\Services\ProductMetricsService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\ConversationStatus;
use App\Support\ProductFunnels;
use App\Support\MessageDeliveryStatus;
use Illuminate\Http\Request;

class SendConversationTemplateAction
{
    public function __construct(
        private readonly WhatsAppSendService $whatsAppSend,
        private readonly MessageDeliveryStatusService $deliveryStatus,
        private readonly AuditLogService $auditLog,
        private readonly CompanyUsageLimitsService $usageLimits,
        private readonly UpdateConversationStatusAction $statusAction,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function handle(Request $request, User $user, int $conversationId): ActionResponse
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company'])
            ->first();

        if (! $conversation) {
            return ActionResponse::notFound('Conversa não encontrada.');
        }

        $validated = $request->validated();

        $templateName = trim((string) ($validated['template_name'] ?? 'iniciar_conversa'));
        if ($templateName === '') {
            $templateName = 'iniciar_conversa';
        }

        $limitCheck = $this->usageLimits->checkAndConsume((int) $conversation->company_id, 'template');
        if (! $limitCheck->allowed) {
            return $limitCheck->toBlockedResponse();
        }

        $sendResult   = $this->whatsAppSend->sendTemplateMessage(
            $conversation->company,
            $conversation->customer_phone,
            $templateName
        );
        $templateSent = (bool) ($sendResult['ok'] ?? false);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'out',
            'type'            => 'human',
            'content_type'    => 'text',
            'text'            => "[Template: {$templateName}]",
            'delivery_status' => $templateSent ? MessageDeliveryStatus::SENT : MessageDeliveryStatus::FAILED,
            'meta'            => [
                'source'          => 'template',
                'template_name'   => $templateName,
                'actor_user_id'   => $user->id,
                'actor_user_name' => $user->name,
                'send_result'     => $sendResult,
            ],
        ]);

        AuditService::log(
            action: 'send_message',
            entityType: 'message',
            entityId: $message->id,
            newData: $this->buildMessageAuditSummary($message, $conversation)
        );

        if ($templateSent) {
            $conversation->last_business_message_at = now();
            if ($conversation->status === ConversationStatus::CLOSED) {
                $this->statusAction->reopen($conversation);
            }
            $conversation->save();
        }

        $this->deliveryStatus->applySendResult($message, $sendResult, 'template_manual');
        $message->refresh();
        $conversation->load(['currentArea:id,name', 'assignedUser:id,name,email']);

        $this->auditLog->record($request, 'company.conversation.send_template', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'template_name'   => $templateName,
            'sent'            => $templateSent,
        ]);

        $this->productMetrics->track(
            ProductFunnels::FEATURE_PRINCIPAL,
            'manual_or_template_sent',
            'template_message_sent',
            (int) $conversation->company_id,
            (int) $user->id,
            [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'template_name' => $templateName,
                'was_sent' => $templateSent,
            ],
        );

        return ActionResponse::ok(array_merge(
            [
                'ok'           => $templateSent,
                'message'      => $message,
                'conversation' => $conversation,
                'error'        => $templateSent ? null : ($sendResult['error'] ?? 'Falha ao enviar template.'),
            ],
            $limitCheck->warningPayload()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessageAuditSummary(Message $message, Conversation $conversation): array
    {
        $textPreview = null;
        if (is_string($message->text) && trim($message->text) !== '') {
            $textPreview = mb_substr(trim($message->text), 0, 120);
        }

        return [
            'conversation_id' => $conversation->id,
            'source'          => 'template',
            'direction'       => $message->direction,
            'type'            => $message->type,
            'content_type'    => $message->content_type,
            'delivery_status' => $message->delivery_status,
            'has_media'       => ! empty($message->media_key),
            'text_preview'    => $textPreview,
        ];
    }
}
