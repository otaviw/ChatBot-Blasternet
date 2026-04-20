<?php

namespace App\Actions\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

class ToggleConversationPrivacyAction
{
    /**
     * @return array{path: string, download_name: string}|array{error: string, status: int}
     *
     * @throws ModelNotFoundException
     */
    public function handle(User $user, int $conversationId, int $messageId): array
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->findOrFail($conversationId);

        $message = Message::query()->findOrFail($messageId);

        if ((int) $message->conversation_id !== (int) $conversation->id) {
            abort(404);
        }

        if (! $message->media_key) {
            return [
                'error' => 'Sem arquivo',
                'status' => 404,
            ];
        }

        $disk = Storage::disk('public');
        $basePath = rtrim($disk->path(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $filePath = $disk->path($message->media_key);

        if (! str_starts_with($filePath, $basePath)) {
            abort(404);
        }

        if (! file_exists($filePath)) {
            return [
                'error' => 'Arquivo não encontrado',
                'status' => 404,
            ];
        }

        return [
            'path' => $filePath,
            'download_name' => basename($message->media_filename ?? 'arquivo'),
        ];
    }
}

