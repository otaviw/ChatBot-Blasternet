<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature   = 'backup:database';
    protected $description = 'Gera backup comprimido do banco MySQL e mantém os últimos 7 arquivos';

    public function handle(): int
    {
        $scriptPath = base_path('../scripts/backup-db.sh');
        $backendPath = base_path();

        if (! file_exists($scriptPath)) {
            $this->error("Script não encontrado: {$scriptPath}");
            return self::FAILURE;
        }

        $this->info('[backup:database] Iniciando backup...');

        $command = sprintf(
            'bash %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($backendPath)
        );

        $output    = [];
        $exitCode  = 0;
        exec($command, $output, $exitCode);

        foreach ($output as $line) {
            if (str_contains(strtolower($line), 'erro') || str_contains(strtolower($line), 'error')) {
                $this->error($line);
            } else {
                $this->line($line);
            }
        }

        if ($exitCode !== 0) {
            $this->error('[backup:database] Backup falhou (exit code: ' . $exitCode . ')');
            return self::FAILURE;
        }

        $this->info('[backup:database] Backup concluído com sucesso.');
        return self::SUCCESS;
    }
}
