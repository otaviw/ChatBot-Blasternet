<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Services\Company\CompanyConversationCountersService;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ConversationObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RealtimePublisher $publisher,
        private readonly CompanyConversationCountersService $countersService
    ) {}

    public function created(Conversation $conversation): void
    {
        $this->publishCountersUpdateIfPossible($conversation);
    }

    public function updated(Conversation $conversation): void
    {
        if (! $conversation->wasChanged(['status', 'current_area_id'])) {
            return;
        }

        $this->publishCountersUpdateIfPossible($conversation);
    }

    private function publishCountersUpdateIfPossible(Conversation $conversation): void
    {
        if (! $conversation->company_id) {
            return;
        }

        $companyId = (int) $conversation->company_id;
        $this->publisher->publish(
            RealtimeEvents::CONVERSATION_COUNTERS_UPDATED,
            ["company:{$companyId}"],
            [
                'company_id' => $companyId,
                'conversation_id' => (int) $conversation->id,
                'counters' => $this->countersService->buildForCompany($companyId),
            ]
        );
    }
}
