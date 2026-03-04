<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
        ]);

        $companyFilter = trim((string) ($validated['company_id'] ?? ''));
        $statusFilter = trim((string) ($validated['status'] ?? ''));

        $query = SupportTicket::query()
            ->with(['company:id,name', 'requester:id,name,email', 'managedBy:id,name,email'])
            ->latest('id');

        if ($companyFilter !== '') {
            if ($companyFilter === 'none') {
                $query->whereNull('company_id');
            } elseif (ctype_digit($companyFilter)) {
                $query->where('company_id', (int) $companyFilter);
            }
        }

        if ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        $tickets = $query->limit(500)->get();
        $openTickets = $tickets
            ->where('status', SupportTicket::STATUS_OPEN)
            ->values()
            ->all();
        $closedTickets = $tickets
            ->where('status', SupportTicket::STATUS_CLOSED)
            ->values()
            ->all();

        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn(Company $company) => [
                'id' => (int) $company->id,
                'name' => $company->name,
            ])
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'open_tickets' => array_map(fn(SupportTicket $ticket) => $this->serializeTicket($ticket), $openTickets),
            'closed_tickets' => array_map(fn(SupportTicket $ticket) => $this->serializeTicket($ticket), $closedTickets),
            'companies' => $companies,
            'filters' => [
                'company_id' => $companyFilter === '' ? null : $companyFilter,
                'status' => $statusFilter === '' ? null : $statusFilter,
            ],
            'counts' => [
                'open' => count($openTickets),
                'closed' => count($closedTickets),
                'total' => count($openTickets) + count($closedTickets),
            ],
        ]);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $ticket->load(['company:id,name', 'requester:id,name,email', 'managedBy:id,name,email']);

        return response()->json([
            'authenticated' => true,
            'ticket' => $this->serializeTicket($ticket),
        ]);
    }

    public function updateStatus(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([SupportTicket::STATUS_OPEN, SupportTicket::STATUS_CLOSED])],
        ]);

        $nextStatus = (string) $validated['status'];
        $previousStatus = (string) $ticket->status;

        $ticket->status = $nextStatus;
        if ($nextStatus === SupportTicket::STATUS_CLOSED) {
            $ticket->closed_at = now();
            $ticket->managed_by_user_id = (int) $user->id;
        } else {
            $ticket->closed_at = null;
            $ticket->managed_by_user_id = null;
        }
        $ticket->save();
        $ticket->load(['company:id,name', 'requester:id,name,email', 'managedBy:id,name,email']);

        $this->auditLog->record($request, 'support.ticket.status_updated', $ticket->company_id, [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'before_status' => $previousStatus,
            'after_status' => $ticket->status,
        ]);

        return response()->json([
            'ok' => true,
            'ticket' => $this->serializeTicket($ticket),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTicket(SupportTicket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'ticket_number' => (int) ($ticket->ticket_number ?: $ticket->id),
            'company_id' => $ticket->company_id ? (int) $ticket->company_id : null,
            'company_name' => $ticket->company?->name ?? $ticket->requester_company_name,
            'requester_user_id' => $ticket->requester_user_id ? (int) $ticket->requester_user_id : null,
            'requester_name' => $ticket->requester_name,
            'requester_contact' => $ticket->requester_contact,
            'requester_company_name' => $ticket->requester_company_name,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'status' => $ticket->status,
            'managed_by_user_id' => $ticket->managed_by_user_id ? (int) $ticket->managed_by_user_id : null,
            'managed_by_name' => $ticket->managedBy?->name,
            'closed_at' => $ticket->closed_at,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }
}
