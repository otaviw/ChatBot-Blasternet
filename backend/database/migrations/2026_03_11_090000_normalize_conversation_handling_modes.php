<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')
            ->where('handling_mode', 'manual')
            ->update(['handling_mode' => 'human']);
    }

    public function down(): void
    {
        // Irreversible normalization: legacy "manual" values are merged into "human".
    }
};
