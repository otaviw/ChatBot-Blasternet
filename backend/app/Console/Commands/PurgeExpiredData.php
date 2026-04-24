<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\Message;
use Illuminate\Console\Command;

class PurgeExpiredData extends Command
{
    protected $signature = 'data:purge-expired';

    protected $description = 'Remove mensagens e logs de uso de IA mais antigos que o tempo configurado por empresa';

    private const CHUNK_SIZE = 500;

    public function handle(): void
    {
        $totalMessages = 0;
        $totalAiLogs = 0;

        Company::query()
            ->select('id')
            ->with('botSetting:id,company_id,message_retention_days,ai_usage_log_retention_days')
            ->whereHas('botSetting', fn ($q) => $q->where(
                fn ($q) => $q
                    ->whereNotNull('message_retention_days')
                    ->orWhereNotNull('ai_usage_log_retention_days')
            ))
            ->chunkById(100, function ($companies) use (&$totalMessages, &$totalAiLogs) {
                foreach ($companies as $company) {
                    $setting = $company->botSetting;

                    if ($setting?->message_retention_days !== null) {
                        $totalMessages += $this->purgeMessages(
                            (int) $company->id,
                            (int) $setting->message_retention_days
                        );
                    }

                    if ($setting?->ai_usage_log_retention_days !== null) {
                        $totalAiLogs += $this->purgeAiUsageLogs(
                            (int) $company->id,
                            (int) $setting->ai_usage_log_retention_days
                        );
                    }
                }
            });

        $this->info("Mensagens removidas: {$totalMessages}. Logs de IA removidos: {$totalAiLogs}.");
    }

    private function purgeMessages(int $companyId, int $retentionDays): int
    {
        if ($retentionDays < 1) {
            return 0;
        }

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        while (true) {
            $ids = Message::query()
                ->select('messages.id')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.company_id', $companyId)
                ->where('messages.created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->pluck('messages.id');

            if ($ids->isEmpty()) {
                break;
            }

            Message::query()->whereIn('id', $ids)->delete();
            $deleted += $ids->count();

            if ($ids->count() < self::CHUNK_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    private function purgeAiUsageLogs(int $companyId, int $retentionDays): int
    {
        if ($retentionDays < 1) {
            return 0;
        }

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        while (true) {
            $count = AiUsageLog::query()
                ->where('company_id', $companyId)
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->delete();

            $deleted += $count;

            if ($count < self::CHUNK_SIZE) {
                break;
            }
        }

        return $deleted;
    }
}
