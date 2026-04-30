<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Area;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use Illuminate\Support\Facades\DB;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Validation\ValidationException;

class TransferConversationService
{
    public function __construct(
        private WhatsAppSendService $whatsAppSend,
        private MessageDeliveryStatusService $deliveryStatus
    ) {}

    public function transfer(
        Conversation $conversation,
        User $actor,
        string $targetType,
        int $targetId,
        bool $sendOutbound = true
    ): array {
        $normalizedType = $this->normalizeTargetType($targetType);
        if (! in_array($normalizedType, [ConversationAssignedType::USER, ConversationAssignedType::AREA], true)) {
            throw ValidationException::withMessages([
                'type' => ['Tipo de transferência inválido. Use user ou area.'],
            ]);
        }

        if ($targetId <= 0) {
            throw ValidationException::withMessages([
                'id' => ['Id de destino inválido.'],
            ]);
        }

        return DB::transaction(function () use ($conversation, $actor, $normalizedType, $targetId, $sendOutbound) {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->with('company')
                ->first();

            if (! $lockedConversation) {
                throw ValidationException::withMessages([
                    'conversation_id' => ['Conversa não encontrada.'],
                ]);
            }

            if ((int) $lockedConversation->company_id !== (int) $actor->company_id) {
                throw ValidationException::withMessages([
                    'conversation_id' => ['Conversa não pertence à empresa do usuário.'],
                ]);
            }

            [$targetEntity, $targetLabel, $currentAreaId, $assignedUserId, $assignedArea] = $this->resolveTarget(
                (int) $lockedConversation->company_id,
                $normalizedType,
                $targetId
            );

            $fromAssignedType = $this->normalizeAssignedType($lockedConversation->assigned_type);
            $fromAssignedId = $lockedConversation->assigned_id ? (int) $lockedConversation->assigned_id : null;

            $lockedConversation->assigned_type = $normalizedType;
            $lockedConversation->assigned_id = $targetId;
            $lockedConversation->handling_mode = ConversationHandlingMode::HUMAN;
            $lockedConversation->current_area_id = $currentAreaId;
            $lockedConversation->assigned_user_id = $assignedUserId;
            $lockedConversation->assigned_area = $assignedArea;
            $lockedConversation->assumed_at = now();
            $lockedConversation->status = ConversationStatus::IN_PROGRESS;
            $lockedConversation->save();

            $transfer = ConversationTransfer::create([
                'company_id' => (int) $lockedConversation->company_id,
                'conversation_id' => (int) $lockedConversation->id,
                'from_assigned_type' => $fromAssignedType,
                'from_assigned_id' => $fromAssignedId,
                'to_assigned_type' => $normalizedType,
                'to_assigned_id' => $targetId,
                'transferred_by_user_id' => (int) $actor->id,
            ]);

            $text = "Conversa transferida para {$targetLabel}";
            $message = Message::create([
                'conversation_id' => (int) $lockedConversation->id,
                'direction' => 'out',
                'type' => 'system',
                'text' => $text,
                'delivery_status' => MessageDeliveryStatus::PENDING,
                'meta' => [
                    'source' => 'transfer',
                    'from_assigned_type' => $fromAssignedType,
                    'from_assigned_id' => $fromAssignedId,
                    'to_assigned_type' => $normalizedType,
                    'to_assigned_id' => $targetId,
                    'transferred_by_user_id' => (int) $actor->id,
                ],
            ]);

            $sendResult = $sendOutbound
                ? $this->whatsAppSend->sendText($lockedConversation->company, $lockedConversation->customer_phone, $text)
                : null;
            $wasSent = (bool) ($sendResult['ok'] ?? false);

            if ($sendResult !== null) {
                $this->deliveryStatus->applySendResult($message, $sendResult, 'conversation_transfer');
            }

            $meta = $message->meta ?? [];
            $meta['send_outbound'] = $sendOutbound;
            $meta['was_sent'] = $wasSent;
            $message->meta = $meta;
            $message->save();

            return [
                'conversation' => $lockedConversation->fresh(['assignedUser:id,name,email', 'currentArea:id,name']),
                'transfer' => $transfer,
                'message' => $message,
                'target' => $targetEntity,
                'target_label' => $targetLabel,
                'was_sent' => $wasSent,
            ];
        });
    }

    public function transferOptions(int $companyId): array
    {
        $areas = Area::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $users = User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', User::companyRoleValues())
            ->where('is_active', true)
            ->with('areas:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'company_id']);

        return [
            'areas' => $areas->map(fn(Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
            ])->values()->all(),
            'users' => $users->map(fn(User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'areas' => $user->areas
                    ->map(fn(Area $area) => ['id' => $area->id, 'name' => $area->name])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    private function resolveTarget(int $companyId, string $type, int $id): array
    {
        if ($type === ConversationAssignedType::USER) {
            $user = User::query()
                ->where('company_id', $companyId)
                ->whereIn('role', User::companyRoleValues())
                ->where('is_active', true)
                ->with('areas:id,name')
                ->find($id);

            if (! $user) {
                throw ValidationException::withMessages([
                    'id' => ['Usuário destino não encontrado para esta empresa.'],
                ]);
            }

            $primaryArea = $user->areas->sortBy('name')->first();

            return [
                $user,
                $user->name,
                $primaryArea?->id ? (int) $primaryArea->id : null,
                (int) $user->id,
                $primaryArea?->name ? (string) $primaryArea->name : null,
            ];
        }

        $area = Area::query()
            ->where('company_id', $companyId)
            ->find($id);

        if (! $area) {
            throw ValidationException::withMessages([
                'id' => ['Área destino não encontrada para esta empresa.'],
            ]);
        }

        return [$area, $area->name, (int) $area->id, null, (string) $area->name];
    }

    private function normalizeTargetType(string $type): string
    {
        return mb_strtolower(trim($type));
    }

    private function normalizeAssignedType(?string $type): string
    {
        $value = $this->normalizeTargetType((string) $type);
        if (in_array($value, ConversationAssignedType::all(), true)) {
            return $value;
        }

        return ConversationAssignedType::UNASSIGNED;
    }
}
