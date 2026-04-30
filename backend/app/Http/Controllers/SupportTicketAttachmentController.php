<?php

declare(strict_types=1);


namespace App\Http\Controllers;

use App\Models\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportTicketAttachmentController extends Controller
{
    public function media(Request $request, SupportTicketAttachment $attachment)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $ticket = $attachment->supportTicket;
        if (! $ticket) {
            return response()->json(['message' => 'Anexo não encontrado.'], 404);
        }

        if (! $user->isSystemAdmin() && (int) $ticket->requester_user_id !== (int) $user->id) {
            return response()->json(['message' => 'Você não tem permissão para acessar este anexo.'], 403);
        }

        $disk = $attachment->storage_provider ?: (string) config('whatsapp.media_disk', 'public');
        if (! Storage::disk($disk)->exists($attachment->storage_key)) {
            return response()->json(['message' => 'Arquivo de mídia não encontrado.'], 404);
        }

        $headers = [];
        if ($attachment->mime_type) {
            $headers['Content-Type'] = (string) $attachment->mime_type;
        }

        return Storage::disk($disk)->response($attachment->storage_key, null, $headers);
    }
}

