<?php

namespace App\Jobs;

use App\Mail\UnattendedConversationsAlertMail;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class AlertUnattendedConversationsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        // Process each company that has unattended_alert_hours configured
        Company::query()
            ->whereHas('botSetting', function ($q) {
                $q->whereNotNull('unattended_alert_hours')->where('unattended_alert_hours', '>', 0);
            })
            ->with(['botSetting'])
            ->chunkById(20, function ($companies) {
                foreach ($companies as $company) {
                    $this->processCompany($company);
                }
            });
    }

    private function processCompany(Company $company): void
    {
        $alertHours = (int) ($company->botSetting?->unattended_alert_hours ?? 0);
        if ($alertHours <= 0) {
            return;
        }

        $threshold = Carbon::now()->subHours($alertHours);

        // Open conversations where customer last messaged more than N hours ago
        // and business hasn't replied since (or hasn't replied at all)
        $unattended = Conversation::query()
            ->where('company_id', $company->id)
            ->where('status', 'open')
            ->whereNotNull('last_user_message_at')
            ->where('last_user_message_at', '<=', $threshold)
            ->where(function ($q) {
                $q->whereNull('last_business_message_at')
                    ->orWhereColumn('last_business_message_at', '<', 'last_user_message_at');
            })
            ->count();

        if ($unattended === 0) {
            return;
        }

        // Send alert to all active company admins
        $admins = User::query()
            ->where('company_id', $company->id)
            ->where('role', User::ROLE_COMPANY_ADMIN)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        foreach ($admins as $admin) {
            Mail::to($admin->email)
                ->send(new UnattendedConversationsAlertMail($company, $admin, $unattended, $alertHours));
        }
    }
}
