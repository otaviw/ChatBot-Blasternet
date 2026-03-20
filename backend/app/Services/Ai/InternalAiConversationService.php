<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InternalAiConversationService
{
    public function ensureInternalChatEnabled(User $user): void
    {
        $this->requireInternalChatSettings($user);
    }

    public function requireInternalChatSettings(User $user): CompanyBotSetting
    {
        $companyId = $this->requireCompanyUser($user);

        $settings = CompanyBotSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if (! ($settings?->ai_enabled ?? false)) {
            throw ValidationException::withMessages([
                'ai' => ['IA desabilitada para esta empresa.'],
            ]);
        }

        if (! ($settings?->ai_internal_chat_enabled ?? false)) {
            throw ValidationException::withMessages([
                'ai' => ['Chat interno com IA desabilitado para esta empresa.'],
            ]);
        }

        return $settings;
    }

    public function assertOwnedInternalConversation(AiConversation $conversation, User $user): void
    {
        if ((int) $conversation->company_id !== (int) $user->company_id) {
            throw ValidationException::withMessages([
                'conversation' => ['Conversa de IA nao pertence a empresa do usuario.'],
            ]);
        }

        if ((string) $conversation->origin !== AiConversation::ORIGIN_INTERNAL_CHAT) {
            throw ValidationException::withMessages([
                'conversation' => ['Conversa de IA invalida para o chat interno.'],
            ]);
        }

        if ($conversation->opened_by_user_id !== null
            && (int) $conversation->opened_by_user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'conversation' => ['Conversa de IA nao pertence ao usuario autenticado.'],
            ]);
        }
    }

    public function queryForUser(User $user): Builder
    {
        return AiConversation::query()
            ->where('company_id', (int) $user->company_id)
            ->where('opened_by_user_id', (int) $user->id)
            ->where('origin', AiConversation::ORIGIN_INTERNAL_CHAT);
    }

    public function findForUser(User $user, int $conversationId): ?AiConversation
    {
        return $this->queryForUser($user)
            ->whereKey($conversationId)
            ->first();
    }

    public function createForUser(User $user, ?string $title = null): AiConversation
    {
        $normalizedTitle = trim((string) $title);
        if ($normalizedTitle !== '') {
            $normalizedTitle = mb_substr($normalizedTitle, 0, 190);
        }

        return AiConversation::query()->create([
            'company_id' => (int) $user->company_id,
            'opened_by_user_id' => (int) $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
            'title' => $normalizedTitle !== '' ? $normalizedTitle : null,
            'meta' => null,
            'last_message_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversationSummary(AiConversation $conversation): array
    {
        $lastMessage = $conversation->lastMessage;

        return [
            'id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'opened_by_user_id' => $conversation->opened_by_user_id !== null
                ? (int) $conversation->opened_by_user_id
                : null,
            'origin' => (string) $conversation->origin,
            'title' => $conversation->title ? (string) $conversation->title : null,
            'last_message' => $lastMessage ? $this->serializeMessage($lastMessage) : null,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversationDetail(AiConversation $conversation): array
    {
        $summary = $this->serializeConversationSummary($conversation);

        $summary['messages'] = $conversation->messages
            ->map(fn (AiMessage $message): array => $this->serializeMessage($message))
            ->values()
            ->all();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMessage(AiMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'ai_conversation_id' => (int) $message->ai_conversation_id,
            'user_id' => $message->user_id !== null ? (int) $message->user_id : null,
            'role' => (string) $message->role,
            'content' => (string) ($message->content ?? ''),
            'provider' => $message->provider ? (string) $message->provider : null,
            'model' => $message->model ? (string) $message->model : null,
            'response_time_ms' => $message->response_time_ms !== null
                ? (int) $message->response_time_ms
                : null,
            'meta' => is_array($message->meta) ? $message->meta : [],
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
        ];
    }

    private function requireCompanyUser(User $user): int
    {
        $companyId = (int) ($user->company_id ?? 0);

        if (! $user->isCompanyUser() || $companyId <= 0) {
            throw ValidationException::withMessages([
                'user' => ['Usuario sem permissao para usar o chat interno com IA.'],
            ]);
        }

        return $companyId;
    }
}
