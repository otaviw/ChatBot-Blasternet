<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSmokeTest extends Command
{
    protected $signature = 'db:smoke';

    protected $description = 'Executa smoke test de banco pos-migrate para validar schema e queries criticas';

    public function handle(): int
    {
        $this->info('[db:smoke] Iniciando validacoes de banco...');

        try {
            DB::select('SELECT 1 AS ok');
        } catch (\Throwable $exception) {
            $this->error('[db:smoke] Falha na conexao com banco: '.$exception->getMessage());

            return self::FAILURE;
        }

        $criticalTables = [
            'users',
            'conversations',
            'messages',
            'chat_conversations',
            'chat_messages',
            'support_tickets',
            'contacts',
            'ai_prompt_histories',
        ];

        foreach ($criticalTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                $this->error("[db:smoke] Tabela critica ausente: {$tableName}");

                return self::FAILURE;
            }
        }

        $checks = [
            ['users', fn () => DB::table('users')->whereNotNull('email')->orderBy('id')->limit(1)->get()],
            ['conversations', fn () => DB::table('conversations')->whereNotNull('company_id')->orderByDesc('id')->limit(1)->get()],
            ['messages', fn () => DB::table('messages')->whereNotNull('conversation_id')->orderByDesc('id')->limit(1)->get()],
            ['chat_conversations', fn () => DB::table('chat_conversations')->whereNull('deleted_at')->orderByDesc('id')->limit(1)->get()],
            ['chat_messages', fn () => DB::table('chat_messages')->whereNotNull('conversation_id')->orderByDesc('id')->limit(1)->get()],
            ['support_tickets', fn () => DB::table('support_tickets')->whereIn('status', ['open', 'closed'])->orderByDesc('id')->limit(1)->get()],
            ['contacts', fn () => DB::table('contacts')->whereNotNull('company_id')->orderBy('name')->orderBy('id')->limit(1)->get()],
            ['ai_prompt_histories', fn () => DB::table('ai_prompt_histories')->orderByDesc('id')->limit(1)->get()],
        ];

        foreach ($checks as [$label, $queryRunner]) {
            try {
                $queryRunner();
            } catch (\Throwable $exception) {
                $this->error("[db:smoke] Falha em query critica ({$label}): {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info('[db:smoke] OK - schema e queries criticas validados.');

        return self::SUCCESS;
    }
}
