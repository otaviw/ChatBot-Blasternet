<?php

declare(strict_types=1);


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

        if ($sender->isSystemAdmin()) {
            return true;
        }

        if ($recipient->isSystemAdmin()) {
            return true;
        }

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

