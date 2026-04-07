<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Muda ai_temperature de decimal(4,2) para double precision.
     *
     * Motivação: decimal(4,2) arredonda silenciosamente valores como 0.777 → 0.78.
     * double precision preserva precisão total (15+ casas decimais), adequado para
     * parâmetros de modelo de linguagem que usam valores como 0.7, 0.95, 1.0 etc.
     *
     * SQLite (usado nos testes) não tem ALTER COLUMN: tratamos graciosamente.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE company_bot_settings
                 ALTER COLUMN ai_temperature TYPE double precision
                 USING ai_temperature::double precision'
            );
        }
        // SQLite e MySQL/MariaDB: já tratam a coluna como float nativamente;
        // nenhuma alteração estrutural necessária nesses drivers.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE company_bot_settings
                 ALTER COLUMN ai_temperature TYPE decimal(4,2)
                 USING ai_temperature::decimal(4,2)'
            );
        }
    }
};
