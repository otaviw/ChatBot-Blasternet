<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;

class ChatPolicy
{
    /**
     * Verifica se o usuário pode enviar mensagem para outro usuário.
     */
    public function canMessage(User $sender, User $recipient): bool
    {
        if (! $sender->is_active || ! $recipient->is_active) {
            return false;
        }

        // Admin do sistema pode falar com qualquer usuário.
        if ($sender->isSystemAdmin()) {
            return true;
        }

        // Qualquer usuário pode falar com admin do sistema.
        if ($recipient->isSystemAdmin()) {
            return true;
        }

        // Usuários ativos da mesma empresa podem conversar.
        return $sender->company_id !== null
            && $recipient->company_id !== null
            && (int) $sender->company_id === (int) $recipient->company_id;
    }

    /**
     * Verifica se o usuário pode ver/interagir com a conversa.
     */
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $conversation->participants()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Verifica se pode enviar mensagem numa conversa.
     */
    public function sendMessage(User $user, ChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}

