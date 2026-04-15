<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketMessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessageAttachmentController extends Controller
{
    public function media(Request $request, SupportTicketMessageAttachment $attachment)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $attachment->loadMissing('message.ticket');
        $ticket = $attachment->message?->ticket;

        if (! $ticket) {
            return response()->json([
                'message' => 'Anexo não encontrado.',
            ], 404);
        }

        if (! $user->isSystemAdmin() && (int) ($ticket->requester_user_id ?? 0) !== (int) $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para acessar este anexo.',
            ], 403);
        }

        $storageKey = trim((string) ($attachment->storage_key ?? ''));
        if ($storageKey === '') {
            return response()->json([
                'message' => 'Arquivo de midia não encontrado.',
            ], 404);
        }

        $disk = $attachment->storage_provider ?: (string) config('whatsapp.media_disk', 'public');
        if (! Storage::disk($disk)->exists($storageKey)) {
            return response()->json([
                'message' => 'Arquivo de midia não encontrado.',
            ], 404);
        }

        $headers = [];
        if ($attachment->mime_type) {
            $headers['Content-Type'] = (string) $attachment->mime_type;
        }

        return Storage::disk($disk)->response($storageKey, null, $headers);
    }
}
