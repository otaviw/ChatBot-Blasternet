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
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('reseller_id')->nullable();
                $table->string('action');
                $table->string('entity_type');
                $table->string('entity_id')->nullable();
                $table->json('old_data')->nullable();
                $table->json('new_data')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('company_id');
                $table->index('reseller_id');
                $table->index('created_at');
            });

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('audit_logs', 'reseller_id')) {
                $table->unsignedBigInteger('reseller_id')->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('audit_logs', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('action');
            }
            if (! Schema::hasColumn('audit_logs', 'entity_id')) {
                $table->string('entity_id')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('audit_logs', 'old_data')) {
                $table->json('old_data')->nullable()->after('entity_id');
            }
            if (! Schema::hasColumn('audit_logs', 'new_data')) {
                $table->json('new_data')->nullable()->after('old_data');
            }
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
            if (Schema::hasColumn('audit_logs', 'new_data')) {
                $table->dropColumn('new_data');
            }
            if (Schema::hasColumn('audit_logs', 'old_data')) {
                $table->dropColumn('old_data');
            }
            if (Schema::hasColumn('audit_logs', 'entity_id')) {
                $table->dropColumn('entity_id');
            }
            if (Schema::hasColumn('audit_logs', 'entity_type')) {
                $table->dropColumn('entity_type');
            }
            if (Schema::hasColumn('audit_logs', 'reseller_id')) {
                $table->dropColumn('reseller_id');
            }
            if (Schema::hasColumn('audit_logs', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
