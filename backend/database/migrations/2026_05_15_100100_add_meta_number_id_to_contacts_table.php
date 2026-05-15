<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'meta_number_id')) {
                $table->foreignId('meta_number_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('company_meta_numbers')
                    ->nullOnDelete();

                $table->index('meta_number_id', 'contacts_meta_number_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'meta_number_id')) {
                try {
                    $table->dropForeign(['meta_number_id']);
                } catch (\Throwable) {
                    // Ignore legacy environments without FK.
                }

                try {
                    $table->dropIndex('contacts_meta_number_id_idx');
                } catch (\Throwable) {
                    // Ignore legacy environments without index.
                }

                $table->dropColumn('meta_number_id');
            }
        });
    }
};

