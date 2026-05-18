<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MetaNumbersBackfillCommand extends Command
{
    protected $signature = 'meta-numbers:backfill-contact-defaults {--dry-run : Apenas simula sem persistir alterações}';

    protected $description = 'Backfill de contatos sem meta_number_id para o número principal ativo da empresa.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(100, (int) config('meta_numbers.backfill_chunk_size', 500));

        $companies = DB::table('companies')->select('id')->orderBy('id')->get();

        $totalUpdated = 0;
        $companiesAffected = 0;

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            $primaryId = DB::table('company_meta_numbers')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('is_primary', true)
                ->value('id');

            if ($primaryId === null) {
                continue;
            }

            $updatedForCompany = 0;
            do {
                $contactIds = DB::table('contacts')
                    ->where('company_id', $companyId)
                    ->whereNull('meta_number_id')
                    ->limit($chunkSize)
                    ->pluck('id');

                if ($contactIds->isEmpty()) {
                    break;
                }

                $updatedForCompany += $contactIds->count();
                if (! $dryRun) {
                    DB::table('contacts')
                        ->whereIn('id', $contactIds->all())
                        ->update([
                            'meta_number_id' => (int) $primaryId,
                            'updated_at' => now(),
                        ]);
                }
            } while (true);

            if ($updatedForCompany > 0) {
                $companiesAffected++;
                $totalUpdated += $updatedForCompany;
                $this->line("Empresa {$companyId}: {$updatedForCompany} contatos " . ($dryRun ? 'seriam atualizados' : 'atualizados') . '.');
            }
        }

        $this->info(sprintf(
            'Backfill finalizado. Empresas afetadas: %d. Contatos %s: %d.',
            $companiesAffected,
            $dryRun ? 'simulados' : 'atualizados',
            $totalUpdated
        ));

        return self::SUCCESS;
    }
}

