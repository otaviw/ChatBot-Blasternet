<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gera secrets criptograficamente seguros para as variáveis críticas do sistema.
 *
 * Uso básico (exibe no terminal, não altera nenhum arquivo):
 *   php artisan app:generate-secrets
 *
 * Gravar direto no .env (faz backup automático antes de alterar):
 *   php artisan app:generate-secrets --write
 *
 * Forçar regeneração mesmo quando o valor já estiver definido:
 *   php artisan app:generate-secrets --write --force
 */
class GenerateSecretsCommand extends Command
{
    protected $signature = 'app:generate-secrets
        {--write  : Escreve os valores gerados diretamente no .env (cria backup automático)}
        {--force  : Sobrescreve mesmo que a variável já tenha um valor definido}';

    protected $description = 'Gera REALTIME_JWT_SECRET, REALTIME_INTERNAL_KEY e WHATSAPP_VERIFY_TOKEN criptograficamente seguros';

    /**
     * Prefixos/substrings reconhecidos como "placeholder não configurado".
     * Usados tanto aqui quanto no AppServiceProvider para detectar valores de exemplo.
     */
    public const PLACEHOLDER_PATTERNS = [
        'change_this',
        'your_meta_app_secret',
        'replace_with_artisan',
        'change_this_phone',
        'change_this_whatsapp',
    ];

    /**
     * Variáveis que este comando pode gerar automaticamente.
     * Chave → número de bytes aleatórios (hex → dobro de chars).
     *
     * @var array<string, int>
     */
    private const GENERATE_MAP = [
        'REALTIME_JWT_SECRET'    => 64,   // 128 hex chars — HS256
        'REALTIME_INTERNAL_KEY'  => 48,   // 96 hex chars — chave HTTP interna
        'WHATSAPP_VERIFY_TOKEN'  => 24,   // 48 hex chars — token de verificação Meta
    ];

    public function handle(): int
    {
        $secrets = $this->buildSecrets();

        if ($this->option('write')) {
            return $this->writeToEnv($secrets);
        }

        $this->displaySecrets($secrets);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function buildSecrets(): array
    {
        $secrets = [];
        foreach (self::GENERATE_MAP as $key => $bytes) {
            $secrets[$key] = bin2hex(random_bytes($bytes));
        }

        return $secrets;
    }

    /** @param array<string, string> $secrets */
    private function displaySecrets(array $secrets): void
    {
        $this->newLine();
        $this->info('Copie as linhas abaixo para o seu arquivo .env:');
        $this->newLine();
        $this->line(str_repeat('─', 70));
        foreach ($secrets as $key => $value) {
            $this->line("{$key}={$value}");
        }
        $this->line(str_repeat('─', 70));
        $this->newLine();
        $this->warn('Estes valores foram gerados apenas nesta execução e NÃO foram salvos.');
        $this->warn('Use --write para gravar automaticamente, ou copie manualmente.');
        $this->newLine();
    }

    /** @param array<string, string> $secrets */
    private function writeToEnv(array $secrets): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('.env não encontrado. Crie-o a partir do .env.example antes de executar --write.');
            return self::FAILURE;
        }

        $backupPath = $envPath . '.backup.' . date('YmdHis');
        if (! copy($envPath, $backupPath)) {
            $this->error("Não foi possível criar backup em {$backupPath}. Abortando por segurança.");
            return self::FAILURE;
        }
        $this->line("  Backup criado → {$backupPath}");

        $content = file_get_contents($envPath);
        $force   = $this->option('force');
        $changed = [];
        $skipped = [];

        foreach ($secrets as $key => $newValue) {
            $pattern = "/^{$key}=(.*)$/m";
            preg_match($pattern, $content, $matches);
            $existing = isset($matches[1]) ? trim($matches[1]) : '';

            $needsUpdate = $existing === '' || $this->isPlaceholder($existing);

            if (! $force && ! $needsUpdate) {
                $skipped[] = $key;
                continue;
            }

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$newValue}", $content);
            } else {
                // Variável não existe no arquivo — adiciona ao final
                $content = rtrim($content) . "\n{$key}={$newValue}\n";
            }

            $changed[] = $key;
        }

        file_put_contents($envPath, $content);

        $this->newLine();

        foreach ($changed as $key) {
            $this->info("  ✓  {$key}  →  gerado e gravado");
        }

        foreach ($skipped as $key) {
            $this->comment("  ─  {$key}  →  já configurado (use --force para regenerar)");
        }

        $this->newLine();

        if ($changed !== []) {
            $this->info('Secrets gravados com sucesso.');
            $this->warn('Reinicie o servidor/workers para aplicar os novos valores.');
        } else {
            $this->info('Nenhuma variável precisou ser atualizada.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Detecta se um valor é um placeholder de exemplo (não um secret real).
     * Utilizado também por AppServiceProvider para validação em boot.
     */
    public static function isPlaceholder(string $value): bool
    {
        $lower = mb_strtolower(trim($value));

        if ($lower === '') {
            return true;
        }

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
