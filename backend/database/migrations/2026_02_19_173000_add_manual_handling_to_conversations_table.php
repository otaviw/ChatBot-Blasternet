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
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('handling_mode', 20)->default('bot')->after('status');
            $table->foreignId('assigned_user_id')->nullable()->after('handling_mode')->constrained('users')->nullOnDelete();
            $table->timestamp('assumed_at')->nullable()->after('assigned_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['handling_mode', 'assumed_at']);
        });
    }
};

