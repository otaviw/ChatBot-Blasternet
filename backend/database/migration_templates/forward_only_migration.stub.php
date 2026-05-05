<?php

use App\Support\Database\ForwardOnlyMigration;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'table_name',
            ['column_a', 'column_b'],
            'table_name_column_a_column_b_idx'
        );

    }

    public function down(): void
    {
        $this->forwardOnlyDown();
    }
};
