<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Models\Message;
use App\Models\User;
use App\Services\Company\CompanyConversationSupportService;
use Illuminate\Support\Facades\Storage;

class ServeCompanyConversationMediaAction
{
    public function __construct(
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    public function handle(User $user, int $messageId)
    {
        $message = Message::query()
            ->whereKey($messageId)
            ->whereNotNull('media_key')
            ->whereHas('conversation', function ($query) use ($user) {
                $query->where('company_id', (int) $user->company_id);
                $this->conversationSupport->applyInboxVisibilityScope($query, $user);
            })
            ->first();

        if (! $message || ! $message->media_key) {
            return response()->json(['message' => 'Mídia não encontrada.'], 404);
        }

        $disk = $message->media_provider ?: (string) config('whatsapp.media_disk', 'public');
        if (! Storage::disk($disk)->exists($message->media_key)) {
            return response()->json(['message' => 'Arquivo de mídia não encontrado.'], 404);
        }

        $headers = [
            'Accept-Ranges' => 'bytes',
        ];

        if ($message->media_mime_type) {
            $mime = (string) $message->media_mime_type;

            if ($mime === 'audio/ogg' && $message->content_type === 'audio') {
                $mime = 'audio/ogg; codecs=opus';
            }

            $headers['Content-Type'] = $mime;
        }

        if ($message->content_type === 'document') {
            $filename = $message->media_filename ?: 'arquivo';
            $headers['Content-Disposition'] = 'attachment; filename="' . addslashes($filename) . '"';
        }

        return Storage::disk($disk)->response($message->media_key, null, $headers);
    }
}
