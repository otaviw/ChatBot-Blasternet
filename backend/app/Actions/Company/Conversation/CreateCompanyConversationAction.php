<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Actions\Conversation\UpdateConversationStatusAction;
use App\Data\ActionResponse;
use App\Http\Requests\Company\CreateConversationRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuditService;
use App\Services\MessageDeliveryStatusService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

class CreateCompanyConversationAction
{
    public function __construct(
        private readonly WhatsAppSendService $whatsAppSend,
        private readonly MessageDeliveryStatusService $deliveryStatus,
        private readonly AuditLogService $auditLog,
        private readonly UpdateConversationStatusAction $statusAction,
    ) {}

    public function handle(CreateConversationRequest $request, User $user): ActionResponse
    {
        $validated = $request->validated();

        $normalizedPhone = PhoneNumberNormalizer::normalizeBrazil((string) $validated['customer_phone']);
        if ($normalizedPhone === '') {
            return ActionResponse::unprocessable('Telefone inválido.');
        }

        $customerName  = trim((string) ($validated['customer_name'] ?? ''));
        $phoneVariants = PhoneNumberNormalizer::variantsForLookup($normalizedPhone);

        $conversation = DB::transaction(function () use ($user, $phoneVariants, $normalizedPhone, $customerName): Conversation {
            $conversation = Conversation::query()
                ->where('company_id', (int) $user->company_id)
                ->whereIn('customer_phone', $phoneVariants !== [] ? $phoneVariants : [$normalizedPhone])
                ->orderByDesc('id')
                ->first();

            if (! $conversation) {
                return Conversation::create([
                    'company_id'     => (int) $user->company_id,
                    'customer_phone' => $normalizedPhone,
                    'status'         => ConversationStatus::OPEN,
                    'assigned_type'  => ConversationAssignedType::UNASSIGNED,
                    'handling_mode'  => ConversationHandlingMode::BOT,
                    'customer_name'  => $customerName ?: null,
                ]);
            }

            if ($customerName !== '' && $conversation->customer_name !== $customerName) {
                $conversation->customer_name = $customerName;
            }

            if ($conversation->customer_phone !== $normalizedPhone) {
                $conversation->customer_phone = $normalizedPhone;
            }

            if ($conversation->status === ConversationStatus::CLOSED) {
                $this->statusAction->reopen($conversation, true);
            }

            $conversation->save();

            return $conversation;
        });
        $conversation->load(['company', 'currentArea:id,name', 'assignedUser:id,name,email']);

        $sendTemplate = (bool) ($validated['send_template'] ?? false);
        $message      = null;
        $templateSent = false;

        if ($sendTemplate) {
            [$message, $templateSent] = $this->sendOpeningTemplate($conversation, $validated, $user);
        }

        $this->auditLog->record($request, 'company.conversation.created', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'send_template'   => $sendTemplate,
            'template_sent'   => $templateSent,
        ]);

        return ActionResponse::ok([
            'ok'            => true,
            'conversation'  => $conversation,
            'message'       => $message,
            'template_sent' => $templateSent,
        ]);
    }

    /**
     * Envia o template de abertura e cria a mensagem correspondente.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Message, 1: bool}
     */
    private function sendOpeningTemplate(Conversation $conversation, array $validated, User $user): array
    {
        $templateName = trim((string) ($validated['template_name'] ?? 'iniciar_conversa'));
        if ($templateName === '') {
            $templateName = 'iniciar_conversa';
        }

        $sendResult   = $this->whatsAppSend->sendTemplateMessage(
            $conversation->company,
            $conversation->customer_phone,
            $templateName
        );

        $templateSent = (bool) ($sendResult['ok'] ?? false);
        $templateText = "[Template: {$templateName}]";

        $message = DB::transaction(function () use ($conversation, $templateText, $templateSent, $templateName, $user, $sendResult): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'out',
                'type'            => 'human',
                'content_type'    => 'text',
                'text'            => $templateText,
                'delivery_status' => $templateSent ? MessageDeliveryStatus::SENT : MessageDeliveryStatus::FAILED,
                'meta'            => [
                    'source'          => 'template',
                    'template_name'   => $templateName,
                    'actor_user_id'   => $user->id,
                    'actor_user_name' => $user->name,
                    'send_result'     => $sendResult,
                ],
            ]);

            if ($templateSent) {
                $conversation->last_business_message_at = now();
                $conversation->save();
            }

            return $message;
        });

        AuditService::log(
            action: 'send_message',
            entityType: 'message',
            entityId: $message->id,
            newData: [
                'conversation_id' => $conversation->id,
                'source'          => 'template',
                'template_name'   => $templateName,
                'delivery_status' => $message->delivery_status,
            ]
        );

        $this->deliveryStatus->applySendResult($message, $sendResult, 'template_manual');
        $message->refresh();

        return [$message, $templateSent];
    }
}
