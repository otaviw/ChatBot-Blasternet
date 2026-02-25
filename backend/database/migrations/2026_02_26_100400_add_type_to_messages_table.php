<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'type')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->enum('type', ['user', 'human', 'bot', 'system'])
                    ->default('user')
                    ->after('direction');
            });
        }

        DB::table('messages')
            ->where('direction', 'in')
            ->update(['type' => 'user']);

        DB::table('messages')
            ->where('direction', 'out')
            ->update(['type' => 'bot']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('messages', 'type')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

