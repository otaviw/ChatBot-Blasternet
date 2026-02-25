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
        if (! Schema::hasColumn('users', 'areas')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('areas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'areas')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('areas')->nullable()->after('is_active');
        });
    }
};

