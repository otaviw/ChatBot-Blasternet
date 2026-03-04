<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE support_tickets ALTER COLUMN ticket_number DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE support_tickets MODIFY ticket_number BIGINT UNSIGNED NULL');
        } else {
            // sqlite e outros: schema novo ja nasce com nullable.
        }

        DB::statement('UPDATE support_tickets SET ticket_number = id WHERE ticket_number IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE support_tickets SET ticket_number = id WHERE ticket_number IS NULL');

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE support_tickets ALTER COLUMN ticket_number SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE support_tickets MODIFY ticket_number BIGINT UNSIGNED NOT NULL');
        } else {
            // sqlite e outros: sem rollback de alter column.
        }
    }
};

