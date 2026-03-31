<?php

namespace App\Observers;

use App\Models\SupportTicket;
use App\Services\NotificationDispatchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SupportTicketObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private NotificationDispatchService $dispatchService
    ) {}

    public function created(SupportTicket $ticket): void
    {
        $this->dispatchService->dispatchSupportTicketCreatedNotification($ticket);
    }

    public function updated(SupportTicket $ticket): void
    {
        if (! $ticket->wasChanged('status')) {
            return;
        }

        if ((string) $ticket->status === SupportTicket::STATUS_CLOSED) {
            $this->dispatchService->dispatchSupportTicketClosedNotification($ticket);
        }
    }
}
