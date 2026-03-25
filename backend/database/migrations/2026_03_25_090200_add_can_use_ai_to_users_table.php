<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'can_use_ai')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $column = Schema::hasColumn('users', 'is_active') ? 'is_active' : 'company_id';

            $table->boolean('can_use_ai')->default(false)->after($column);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'can_use_ai')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_use_ai');
        });
    }
};

