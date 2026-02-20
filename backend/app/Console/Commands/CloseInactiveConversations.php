<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class CloseInactiveConversations extends Command
{
    protected $signature = 'conversations:close-inactive';
    protected $description = 'Fecha conversas sem mensagem por mais de X horas';

    public function handle(): void
    {
        $settings = \App\Models\CompanyBotSetting::with('company')->get();

        $total = 0;

        foreach ($settings as $setting) {
            $hours = $setting->inactivity_close_hours ?? 24;

            $conversations = Conversation::where('company_id', $setting->company_id)
                ->whereIn('status', ['open', 'in_progress'])
                ->where('handling_mode', 'bot')
                ->where('updated_at', '<', now()->subHours($hours))
                ->get();

            foreach ($conversations as $conversation) {
                $conversation->status = 'closed';
                $conversation->closed_at = now();
                $conversation->save();
            }

            $total += $conversations->count();
        }

        $this->info("Fechadas {$total} conversas inativas.");
    }
}
