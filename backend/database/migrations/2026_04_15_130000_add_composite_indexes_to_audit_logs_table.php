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
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['company_id', 'created_at', 'id'], 'audit_logs_company_created_id_idx');
            $table->index(['company_id', 'action', 'created_at'], 'audit_logs_company_action_created_idx');
            $table->index(['company_id', 'user_id', 'created_at'], 'audit_logs_company_user_created_idx');
            $table->index(['reseller_id', 'created_at', 'id'], 'audit_logs_reseller_created_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_company_created_id_idx');
            $table->dropIndex('audit_logs_company_action_created_idx');
            $table->dropIndex('audit_logs_company_user_created_idx');
            $table->dropIndex('audit_logs_reseller_created_id_idx');
        });
    }
};
