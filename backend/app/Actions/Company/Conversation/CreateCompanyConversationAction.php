<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Actions\Conversation\UpdateConversationStatusAction;
use App\Data\ActionResponse;
use App\Http\Requests\Company\CreateConversationRequest;
use App\Exceptions\MetaNumberResolutionException;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuditService;
use App\Services\ContactSendNumberResolver;
use App\Services\Company\CompanyMetaNumberService;
use App\Services\MessageDeliveryStatusService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\AuditActions;
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
        private readonly ContactSendNumberResolver $sendNumberResolver,
        private readonly CompanyMetaNumberService $companyMetaNumberService,
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

        $contact = Contact::query()->firstOrCreate(
            [
                'company_id' => (int) $conversation->company_id,
                'phone' => $normalizedPhone,
            ],
            [
                'name' => $customerName !== '' ? $customerName : $normalizedPhone,
                'source' => 'manual',
                'added_by_user_id' => $user->id,
            ]
        );

        $selectedMetaNumberId = null;
        if (isset($validated['meta_number_id'])) {
            $selectedMetaNumberId = (int) $validated['meta_number_id'];
        } elseif (isset($validated['selected_meta_number_id'])) {
            $selectedMetaNumberId = (int) $validated['selected_meta_number_id'];
        }
        $activeNumbers = $this->companyMetaNumberService->listActive((int) $conversation->company_id);
        $requireSelection = (bool) config('meta_numbers.require_selection_on_new_conversation', false);
        if ($requireSelection && $selectedMetaNumberId === null && $activeNumbers->count() > 1 && $contact->meta_number_id === null) {
            return ActionResponse::unprocessable('Selecione o número de envio para iniciar a conversa.', [
                'selected_meta_number_id' => ['Selecione o número de envio para iniciar a conversa.'],
            ]);
        }

        if ($selectedMetaNumberId !== null) {
            try {
                $this->companyMetaNumberService->assertBelongsToCompanyAndActive((int) $conversation->company_id, $selectedMetaNumberId);
            } catch (\RuntimeException $exception) {
                return $this->metaNumberValidationErrorResponse($exception);
            }
            if ((int) ($contact->meta_number_id ?? 0) !== $selectedMetaNumberId) {
                $beforeMetaNumberId = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;
                $contact->meta_number_id = $selectedMetaNumberId;
                $contact->save();

                AuditService::log(
                    AuditActions::CONTACT_META_NUMBER_CHANGED,
                    'contact',
                    $contact->id,
                    ['before' => ['meta_number_id' => $beforeMetaNumberId]],
                    [
                        'actor_user_id' => (int) $user->id,
                        'company_id' => (int) $conversation->company_id,
                        'entity_type' => 'contact',
                        'entity_id' => (int) $contact->id,
                        'after' => ['meta_number_id' => $selectedMetaNumberId],
                        'reason' => 'conversation_selected_send_number',
                    ]
                );
            }

            AuditService::log(
                AuditActions::CONVERSATION_SEND_NUMBER_SELECTED,
                'conversation',
                $conversation->id,
                null,
                [
                    'actor_user_id' => (int) $user->id,
                    'company_id' => (int) $conversation->company_id,
                    'entity_type' => 'conversation',
                    'entity_id' => (int) $conversation->id,
                    'after' => ['selected_meta_number_id' => $selectedMetaNumberId, 'contact_id' => (int) $contact->id],
                ]
            );
        }

        $sendTemplate = (bool) ($validated['send_template'] ?? false);
        $message      = null;
        $templateSent = false;
        $resolvedMetaNumber = null;

        if ($sendTemplate) {
            try {
                $resolvedMetaNumber = $this->sendNumberResolver->resolveForContact(
                    $contact,
                    true,
                    (int) $conversation->id,
                    null
                );
            } catch (MetaNumberResolutionException $exception) {
                return ActionResponse::unprocessable($exception->errorCode(), ['error' => $exception->errorCode()]);
            }

            [$message, $templateSent] = $this->sendOpeningTemplate($conversation, $validated, $user, $resolvedMetaNumber->id);
        }

        $this->auditLog->record($request, 'company.conversation.created', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'send_template'   => $sendTemplate,
            'template_sent'   => $templateSent,
            'contact_id' => (int) $contact->id,
            'resolved_meta_number_id' => $resolvedMetaNumber?->id !== null ? (int) $resolvedMetaNumber->id : null,
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
    private function sendOpeningTemplate(Conversation $conversation, array $validated, User $user, int $resolvedMetaNumberId): array
    {
        $templateName = trim((string) ($validated['template_name'] ?? 'iniciar_conversa'));
        $templateVariables = collect($validated['template_variables'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();

        if ($templateName === '') {
            $templateName = 'iniciar_conversa';
        }

        $sendResult   = $this->whatsAppSend->sendTemplateMessage(
            $conversation->company,
            $conversation->customer_phone,
            $templateName,
            $templateVariables
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
                    'resolved_meta_number_id' => $resolvedMetaNumberId,
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

    private function metaNumberValidationErrorResponse(\RuntimeException $exception): ActionResponse
    {
        return match ($exception->getMessage()) {
            'META_NUMBER_NOT_FOUND' => ActionResponse::notFound('META_NUMBER_NOT_FOUND'),
            'META_NUMBER_COMPANY_MISMATCH' => ActionResponse::unprocessable('META_NUMBER_COMPANY_MISMATCH', ['error' => 'META_NUMBER_COMPANY_MISMATCH']),
            'META_NUMBER_INACTIVE' => ActionResponse::unprocessable('META_NUMBER_INACTIVE', ['error' => 'META_NUMBER_INACTIVE']),
            default => ActionResponse::unprocessable($exception->getMessage(), ['error' => $exception->getMessage()]),
        };
    }
}
