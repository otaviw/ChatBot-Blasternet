<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListSupportTicketsRequest;
use App\Http\Requests\Admin\UpdateSupportTicketStatusRequest;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog
    ) {}

    public function index(ListSupportTicketsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $companyFilter = trim((string) ($validated['company_id'] ?? ''));
        $statusFilter = trim((string) ($validated['status'] ?? ''));

        $query = SupportTicket::query()
            ->with(['company:id,name', 'requester:id,name,email', 'managedBy:id,name,email', 'attachments'])
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
            'open_tickets' => array_map(fn(SupportTicket $ticket) => $ticket->toApiArray(), $openTickets),
            'closed_tickets' => array_map(fn(SupportTicket $ticket) => $ticket->toApiArray(), $closedTickets),
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

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load(['company:id,name', 'requester:id,name,email', 'managedBy:id,name,email', 'attachments']);

        return response()->json([
            'authenticated' => true,
            'ticket' => $ticket->toApiArray(),
        ]);
    }

    public function updateStatus(UpdateSupportTicketStatusRequest $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

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
            'ticket' => $ticket->toApiArray(),
        ]);
    }

}
