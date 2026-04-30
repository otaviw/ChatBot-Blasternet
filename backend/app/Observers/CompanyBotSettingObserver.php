<?php

declare(strict_types=1);


namespace App\Observers;

use App\Models\CompanyBotSetting;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CompanyBotSettingObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher
    ) {}

    public function saved(CompanyBotSetting $settings): void
    {
        if (! $settings->company_id) {
            return;
        }

        if (! $settings->wasRecentlyCreated && ! $settings->wasChanged()) {
            return;
        }

        $this->publisher->publish(
            RealtimeEvents::BOT_UPDATED,
            ["company:{$settings->company_id}"],
            [
                'companyId' => (int) $settings->company_id,
                'isActive' => (bool) $settings->is_active,
                'timezone' => (string) $settings->timezone,
                'updatedAt' => $settings->updated_at?->toISOString(),
            ]
        );
    }
}
