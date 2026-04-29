<?php

use App\Support\Database\ForwardOnlyMigration;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        // Exemplo: criar indice composto de forma idempotente e segura para deploy repetivel.
        // Ajuste nome da tabela, colunas e nome do indice.
        $this->addIndexIfMissing(
            'table_name',
            ['column_a', 'column_b'],
            'table_name_column_a_column_b_idx'
        );

        // Exemplo para DDL com guards:
        // if (! Schema::hasTable('table_name')) {
        //     return;
        // }
        // if (! Schema::hasColumn('table_name', 'new_column')) {
        //     Schema::table('table_name', function (Blueprint $table): void {
        //         $table->string('new_column', 120)->nullable();
        //     });
        // }
    }

    public function down(): void
    {
        // Forward-only: rollback logico via nova migration compensatoria.
        $this->forwardOnlyDown();
    }
};
