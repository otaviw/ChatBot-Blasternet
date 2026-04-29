<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Services\AuditLogService;
use App\Services\MessageMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly MessageMediaStorageService $mediaStorage
    ) {}

    public function store(Request $request): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $user = $request->user();

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:8000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:' . (config('whatsapp.media_max_size_kb', 5120))],
        ]);

        $ticket = SupportTicket::create([
            'company_id' => $user->company_id ? (int) $user->company_id : null,
            'requester_user_id' => (int) $user->id,
            'requester_name' => (string) $user->name,
            'requester_contact' => (string) ($user->email ?? ''),
            'requester_company_name' => $user->company?->name ?? 'Sistema',
            'subject' => trim((string) $validated['subject']),
            'message' => trim((string) $validated['message']),
            'status' => SupportTicket::STATUS_OPEN,
            'managed_by_user_id' => null,
            'closed_at' => null,
        ]);

        if (! $ticket->ticket_number) {
            $ticket->ticket_number = (int) $ticket->id;
            $ticket->save();
        }

        $images = $request->file('images') ?? [];
        foreach ($images as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            try {
                $stored = $this->mediaStorage->storeSupportTicketImage($file);
                SupportTicketAttachment::create([
                    'support_ticket_id' => $ticket->id,
                    'storage_provider' => $stored['provider'],
                    'storage_key' => $stored['key'],
                    'url' => null,
                    'mime_type' => $stored['mime_type'],
                    'size_bytes' => $stored['size_bytes'],
                ]);
            } catch (\Throwable) {
                // Ignora falha em uma imagem; ticket já foi criado.
            }
        }

        $this->auditLog->record($request, 'support.ticket.created', $ticket->company_id, [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
        ]);

        $ticket->load('attachments');

        return response()->json([
            'ok' => true,
            'ticket' => $ticket->toApiArray(),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $user = $request->user();

        $tickets = SupportTicket::query()
            ->with(['company:id,name', 'managedBy:id,name,email', 'attachments'])
            ->where('requester_user_id', (int) $user->id)
            ->latest('id')
            ->limit(500)
            ->get();

        $openTickets = $tickets
            ->where('status', SupportTicket::STATUS_OPEN)
            ->values()
            ->all();
        $closedTickets = $tickets
            ->where('status', SupportTicket::STATUS_CLOSED)
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'role' => $user->role,
            'company_name' => $user->company?->name,
            'open_tickets' => array_map(fn(SupportTicket $ticket) => $ticket->toApiArray(), $openTickets),
            'closed_tickets' => array_map(fn(SupportTicket $ticket) => $ticket->toApiArray(), $closedTickets),
            'counts' => [
                'open' => count($openTickets),
                'closed' => count($closedTickets),
                'total' => count($openTickets) + count($closedTickets),
            ],
        ]);
    }

    public function showMine(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $user = $request->user();

        if ((int) ($ticket->requester_user_id ?? 0) !== (int) $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para visualizar esta solicitação.',
            ], 403);
        }

        $ticket->load(['company:id,name', 'managedBy:id,name,email', 'attachments']);

        return response()->json([
            'authenticated' => true,
            'role' => $user->role,
            'company_name' => $user->company?->name,
            'ticket' => $ticket->toApiArray(),
        ]);
    }

}
