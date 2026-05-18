<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MetaNumberObservabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MetaNumbersMonitorCommand extends Command
{
    protected $signature = 'meta-numbers:monitor {--days= : Janela de monitoramento em dias (default config)}';

    protected $description = 'Resumo operacional da feature de meta numbers para pós-go-live.';

    public function handle(MetaNumberObservabilityService $observability): int
    {
        $days = (int) ($this->option('days') ?: config('meta_numbers.post_go_live_days', 7));
        $days = max(1, $days);
        $since = now()->subDays($days);

        $this->info("Monitoramento meta numbers - janela: últimos {$days} dias (desde {$since->toDateTimeString()}).");

        $companiesWithoutActive = DB::table('companies')
            ->leftJoin('company_meta_numbers', function ($join): void {
                $join->on('company_meta_numbers.company_id', '=', 'companies.id')
                    ->where('company_meta_numbers.is_active', true);
            })
            ->whereNull('companies.deleted_at')
            ->groupBy('companies.id')
            ->havingRaw('COUNT(company_meta_numbers.id) = 0')
            ->pluck('companies.id');

        $failureSpike = DB::table('campaign_contacts')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_contacts.campaign_id')
            ->selectRaw('campaigns.company_id, COUNT(*) as total_failures')
            ->where('campaign_contacts.updated_at', '>=', $since)
            ->where('campaign_contacts.error', 'NO_ACTIVE_META_NUMBER_FOR_COMPANY')
            ->groupBy('campaigns.company_id')
            ->havingRaw('COUNT(*) >= 20')
            ->get();

        $this->line('Empresas sem número ativo: ' . $companiesWithoutActive->count());
        foreach ($companiesWithoutActive as $companyId) {
            $this->line("- company_id={$companyId}");
        }

        $this->line('Picos de falha NO_ACTIVE_META_NUMBER_FOR_COMPANY: ' . $failureSpike->count());
        foreach ($failureSpike as $row) {
            $this->line("- company_id={$row->company_id}, failures={$row->total_failures}");
        }

        $sampleCompanies = DB::table('companies')->whereNull('deleted_at')->limit(20)->pluck('id');
        foreach ($sampleCompanies as $companyId) {
            $fallbackRate = round($observability->fallbackRate((int) $companyId), 2);
            $invalidRate = round($observability->invalidContactRate((int) $companyId), 2);
            $this->line("company_id={$companyId} fallback_rate={$fallbackRate}% invalid_contact_rate={$invalidRate}%");
        }

        return self::SUCCESS;
    }
}

