<?php

namespace App\Observers;

use App\Models\SupportTicketMessage;
use App\Services\NotificationDispatchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SupportTicketMessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private NotificationDispatchService $dispatchService
    ) {}

    public function created(SupportTicketMessage $message): void
    {
        $this->dispatchService->dispatchSupportTicketMessageNotification($message);
    }
}
