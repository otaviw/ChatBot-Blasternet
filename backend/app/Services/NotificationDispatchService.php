<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Support\ConversationHandlingMode;

class NotificationDispatchService
{
    public function __construct(
        private NotificationService $notificationService,
        private ConversationPresenceService $presenceService,
        private NotificationPreferenceService $preferenceService
    ) {}

    public function dispatchCustomerMessageNotification(Message $message): void
    {
        if ((string) $message->direction !== 'in' || (string) $message->type !== 'user') {
            return;
        }

        $conversation = Conversation::query()
            ->whereKey($message->conversation_id)
            ->first([
                'id',
                'company_id',
                'customer_phone',
                'customer_name',
                'handling_mode',
                'assigned_type',
                'assigned_id',
            ]);

        if (! $conversation || ! $conversation->company_id) {
            return;
        }

        if (! ConversationHandlingMode::isHuman($conversation->handling_mode)) {
            return;
        }

        $recipientIds = $this->resolveConversationRecipients($conversation);
        if ($recipientIds === []) {
            return;
        }

        $titleName = trim((string) ($conversation->customer_name ?: $conversation->customer_phone));
        $title = $titleName === ''
            ? 'Nova mensagem do cliente'
            : "Nova mensagem de {$titleName}";

        $text = $this->messageNotificationText($message);

        foreach ($recipientIds as $recipientId) {
            if (! $this->preferenceService->isEnabled($recipientId, 'customer_message')) {
                continue;
            }

            if ($this->presenceService->isConversationOpenByUser($recipientId, (int) $conversation->id)) {
                continue;
            }

            $this->notificationService->createForUser($recipientId, [
                'type' => 'customer_message',
                'module' => 'inbox',
                'title' => $title,
                'text' => $text,
                'reference_type' => 'conversation',
                'reference_id' => (int) $conversation->id,
                'reference_meta' => [
                    'message_id' => (int) $message->id,
                    'conversation_id' => (int) $conversation->id,
                ],
            ]);
        }
    }

    public function dispatchConversationTransferNotification(ConversationTransfer $transfer): void
    {
        if ((string) $transfer->to_assigned_type !== 'user' || ! $transfer->to_assigned_id) {
            return;
        }

        $targetUserId = $this->findActiveUserId((int) $transfer->to_assigned_id);

        if (! $targetUserId) {
            return;
        }

        $conversation = Conversation::query()
            ->whereKey($transfer->conversation_id)
            ->first(['id', 'customer_phone', 'customer_name']);

        $label = trim((string) ($conversation?->customer_name ?: $conversation?->customer_phone ?: 'cliente'));
        $title = 'Conversa transferida para você';
        $text = "Você recebeu o atendimento de {$label}.";

        $this->sendIfEnabled((int) $targetUserId, 'conversation_transferred', [
            'type' => 'conversation_transferred',
            'module' => 'inbox',
            'title' => $title,
            'text' => $text,
            'reference_type' => 'conversation',
            'reference_id' => (int) $transfer->conversation_id,
            'reference_meta' => [
                'transfer_id' => (int) $transfer->id,
                'conversation_id' => (int) $transfer->conversation_id,
                'from_assigned_type' => (string) $transfer->from_assigned_type,
                'from_assigned_id' => $transfer->from_assigned_id ? (int) $transfer->from_assigned_id : null,
            ],
        ]);
    }

    public function dispatchSupportTicketCreatedNotification(SupportTicket $ticket): void
    {
        $superAdminIds = $this->activeSystemAdminIds();

        if ($superAdminIds === []) {
            return;
        }

        $ticketNumber = (int) ($ticket->ticket_number ?: $ticket->id);
        $title = "Nova solicitacao #{$ticketNumber}";
        $text = trim((string) ($ticket->subject ?: $ticket->message));

        foreach ($superAdminIds as $recipientId) {
            if (! $this->preferenceService->isEnabled($recipientId, 'support_ticket_created')) {
                continue;
            }

            $this->notificationService->createForUser($recipientId, [
                'type' => 'support_ticket_created',
                'module' => 'support',
                'title' => $title,
                'text' => $text === '' ? 'Nova solicitacao de suporte aberta.' : $text,
                'reference_type' => 'support_ticket',
                'reference_id' => (int) $ticket->id,
                'reference_meta' => [
                    'ticket_number' => $ticketNumber,
                    'requester_user_id' => $ticket->requester_user_id ? (int) $ticket->requester_user_id : null,
                    'requester_name' => (string) ($ticket->requester_name ?? ''),
                    'company_id' => $ticket->company_id ? (int) $ticket->company_id : null,
                ],
            ]);
        }
    }

    public function dispatchSupportTicketMessageNotification(SupportTicketMessage $message): void
    {
        $message->loadMissing([
            'ticket:id,ticket_number,requester_user_id',
            'sender:id,name,is_active',
        ]);

        $ticket = $message->ticket;
        if (! $ticket) {
            return;
        }

        $senderId = (int) ($message->sender_user_id ?? 0);
        $requesterId = (int) ($ticket->requester_user_id ?? 0);
        $recipientIds = [];

        if ($senderId > 0 && $requesterId > 0 && $senderId === $requesterId) {
            $recipientIds = $this->activeSystemAdminIds();
        } elseif ($requesterId > 0) {
            $activeRequesterId = $this->findActiveUserId($requesterId);

            if ($activeRequesterId) {
                $recipientIds[] = $activeRequesterId;
            }
        }

        if ($recipientIds === []) {
            return;
        }

        $ticketNumber = (int) ($ticket->ticket_number ?: $ticket->id);
        $senderName = trim((string) ($message->sender?->name ?? 'Usuário'));
        $title = "Nova mensagem na solicitacao #{$ticketNumber}";
        $text = $this->supportTicketMessageNotificationText($message);

        foreach ($recipientIds as $recipientId) {
            if ($recipientId === $senderId) {
                continue;
            }

            if (! $this->preferenceService->isEnabled($recipientId, 'support_ticket_message')) {
                continue;
            }

            $this->notificationService->createForUser($recipientId, [
                'type' => 'support_ticket_message',
                'module' => 'support',
                'title' => $title,
                'text' => $text,
                'reference_type' => 'support_ticket',
                'reference_id' => (int) $ticket->id,
                'reference_meta' => [
                    'ticket_number' => $ticketNumber,
                    'ticket_id' => (int) $ticket->id,
                    'message_id' => (int) $message->id,
                    'sender_id' => $senderId > 0 ? $senderId : null,
                    'sender_name' => $senderName,
                ],
            ]);
        }
    }

    public function dispatchInternalChatMessageNotification(ChatMessage $message): void
    {
        $message->loadMissing([
            'sender:id,name,is_active',
            'conversation',
            'conversation.participants:id,is_active',
            'attachments:id,message_id',
        ]);

        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $sender = $message->sender;
        $senderName = trim((string) ($sender?->name ?? 'Usuário'));
        $title = $senderName === ''
            ? 'Nova mensagem no chat interno'
            : "Nova mensagem de {$senderName}";
        $text = $this->internalChatNotificationText($message);

        $recipientIds = $conversation->participants
            ->filter(function (User $participant) use ($message): bool {
                return (int) $participant->id !== (int) $message->sender_id
                    && (bool) $participant->is_active;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($recipientIds as $recipientId) {
            if (! $this->preferenceService->isEnabled($recipientId, 'internal_chat_message')) {
                continue;
            }

            $this->notificationService->createForUser($recipientId, [
                'type' => 'internal_chat_message',
                'module' => 'internal_chat',
                'title' => $title,
                'text' => $text,
                'reference_type' => 'chat_conversation',
                'reference_id' => (int) $conversation->id,
                'reference_meta' => [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    'sender_id' => (int) $message->sender_id,
                    'sender_name' => $senderName,
                ],
            ]);
        }
    }

    public function dispatchConversationClosedNotification(
        Conversation $conversation,
        string $prevAssignedType,
        ?int $prevAssignedId,
        int $closedByUserId
    ): void {
        if ($prevAssignedType !== 'user' || ! $prevAssignedId || $prevAssignedId === $closedByUserId) {
            return;
        }

        $targetUserId = $this->findActiveUserId($prevAssignedId);

        if (! $targetUserId) {
            return;
        }

        $customerLabel = trim((string) ($conversation->customer_name ?: $conversation->customer_phone));
        $title = 'Conversa encerrada';
        $text = $customerLabel !== ''
            ? "A conversa com {$customerLabel} foi encerrada."
            : 'Uma conversa foi encerrada.';

        $this->sendIfEnabled((int) $targetUserId, 'conversation_closed', [
            'type' => 'conversation_closed',
            'module' => 'inbox',
            'title' => $title,
            'text' => $text,
            'reference_type' => 'conversation',
            'reference_id' => (int) $conversation->id,
            'reference_meta' => [
                'conversation_id' => (int) $conversation->id,
                'closed_by_user_id' => $closedByUserId,
            ],
        ]);
    }

    public function dispatchSupportTicketClosedNotification(SupportTicket $ticket): void
    {
        $requesterId = $ticket->requester_user_id ? (int) $ticket->requester_user_id : 0;
        if ($requesterId <= 0) {
            return;
        }

        $managedById = $ticket->managed_by_user_id ? (int) $ticket->managed_by_user_id : 0;
        if ($requesterId === $managedById) {
            return;
        }

        $activeRequesterId = $this->findActiveUserId($requesterId);

        if (! $activeRequesterId) {
            return;
        }

        $ticketNumber = (int) ($ticket->ticket_number ?: $ticket->id);
        $title = "Solicitacao #{$ticketNumber} encerrada";
        $subject = trim((string) ($ticket->subject ?? ''));
        $text = $subject !== ''
            ? "Sua solicitacao \"{$subject}\" foi encerrada."
            : "Sua solicitacao #{$ticketNumber} foi encerrada.";

        $this->sendIfEnabled((int) $activeRequesterId, 'support_ticket_closed', [
            'type' => 'support_ticket_closed',
            'module' => 'support',
            'title' => $title,
            'text' => $text,
            'reference_type' => 'support_ticket',
            'reference_id' => (int) $ticket->id,
            'reference_meta' => [
                'ticket_number' => $ticketNumber,
                'ticket_id' => (int) $ticket->id,
                'managed_by_user_id' => $managedById > 0 ? $managedById : null,
            ],
        ]);
    }

    public function dispatchChatParticipantAddedNotification(
        ChatConversation $conversation,
        int $addedUserId,
        ?int $addedByUserId
    ): void {
        if ($addedUserId <= 0) {
            return;
        }

        if ($addedByUserId && $addedUserId === $addedByUserId) {
            return;
        }

        $activeAddedUser = $this->findActiveUserId($addedUserId);

        if (! $activeAddedUser) {
            return;
        }

        $adderName = '';
        if ($addedByUserId) {
            $adderName = trim((string) (User::query()
                ->where('id', $addedByUserId)
                ->value('name') ?? ''));
        }

        $groupName = trim((string) ($conversation->name ?? ''));
        $title = 'Você foi adicionado a um grupo';
        if ($adderName !== '' && $groupName !== '') {
            $text = "{$adderName} adicionou você ao grupo \"{$groupName}\".";
        } elseif ($adderName !== '') {
            $text = "{$adderName} adicionou você a um grupo.";
        } elseif ($groupName !== '') {
            $text = "Você foi adicionado ao grupo \"{$groupName}\".";
        } else {
            $text = 'Você foi adicionado a um grupo no chat interno.';
        }

        $this->sendIfEnabled((int) $activeAddedUser, 'chat_participant_added', [
            'type' => 'chat_participant_added',
            'module' => 'internal_chat',
            'title' => $title,
            'text' => $text,
            'reference_type' => 'chat_conversation',
            'reference_id' => (int) $conversation->id,
            'reference_meta' => [
                'conversation_id' => (int) $conversation->id,
                'conversation_name' => $groupName,
                'added_by_user_id' => $addedByUserId,
            ],
        ]);
    }

    /**
     * Verifica a preferência do usuário e, se habilitada, cria a notificação.
     * Centraliza o padrão isEnabled + createForUser usado em destinatários únicos.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendIfEnabled(int $recipientId, string $preferenceType, array $payload): void
    {
        if (! $this->preferenceService->isEnabled($recipientId, $preferenceType)) {
            return;
        }

        $this->notificationService->createForUser($recipientId, $payload);
    }

    /**
     * Retorna o id do usuário se ele existir e estiver ativo, ou null caso contrário.
     */
    private function findActiveUserId(int $userId): ?int
    {
        $found = User::query()
            ->where('id', $userId)
            ->where('is_active', true)
            ->value('id');

        return $found ? (int) $found : null;
    }

    /**
     * Retorna a lista de ids de todos os admins do sistema ativos.
     *
     * @return array<int, int>
     */
    private function activeSystemAdminIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', User::ROLE_SYSTEM_ADMIN)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function resolveConversationRecipients(Conversation $conversation): array
    {
        $assignedType = (string) $conversation->assigned_type;
        $assignedId = $conversation->assigned_id ? (int) $conversation->assigned_id : null;

        if ($assignedType === 'user' && $assignedId) {
            $targetUserId = User::query()
                ->where('id', $assignedId)
                ->where('company_id', (int) $conversation->company_id)
                ->where('is_active', true)
                ->value('id');

            return $targetUserId ? [(int) $targetUserId] : [];
        }

        if ($assignedType !== 'area' || ! $assignedId) {
            return [];
        }

        return User::query()
            ->where('company_id', (int) $conversation->company_id)
            ->whereIn('role', User::companyRoleValues())
            ->where('is_active', true)
            ->whereHas('areas', fn ($query) => $query->where('areas.id', $assignedId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    private function messageNotificationText(Message $message): string
    {
        $contentType = (string) ($message->content_type ?: 'text');
        $text = trim((string) ($message->text ?? ''));

        if ($contentType === 'image') {
            if ($text !== '') {
                return "Cliente enviou uma imagem: {$text}";
            }

            return 'Cliente enviou uma imagem.';
        }

        if ($text !== '') {
            return mb_substr($text, 0, 240);
        }

        return 'Cliente enviou uma nova mensagem.';
    }

    private function internalChatNotificationText(ChatMessage $message): string
    {
        $text = trim((string) ($message->content ?? ''));

        if ((string) $message->type === 'image') {
            return $text !== ''
                ? 'Enviou uma imagem: '.mb_substr($text, 0, 220)
                : 'Enviou uma imagem no chat interno.';
        }

        if ((string) $message->type === 'file') {
            return $text !== ''
                ? 'Enviou um arquivo: '.mb_substr($text, 0, 220)
                : 'Enviou um arquivo no chat interno.';
        }

        if ($text !== '') {
            return mb_substr($text, 0, 240);
        }

        if ($message->attachments()->exists()) {
            return 'Enviou um anexo no chat interno.';
        }

        return 'Você recebeu uma nova mensagem no chat interno.';
    }

    private function supportTicketMessageNotificationText(SupportTicketMessage $message): string
    {
        $text = trim((string) ($message->content ?? ''));

        if ((string) $message->type === SupportTicketMessage::TYPE_IMAGE) {
            return $text !== ''
                ? 'Enviou uma imagem: '.mb_substr($text, 0, 220)
                : 'Enviou uma imagem na solicitacao de suporte.';
        }

        if ($text !== '') {
            return mb_substr($text, 0, 240);
        }

        return 'Você recebeu uma nova mensagem na solicitacao de suporte.';
    }
}
