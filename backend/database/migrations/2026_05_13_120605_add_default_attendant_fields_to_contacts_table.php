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
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'default_attendant_user_id')) {
                $table->unsignedBigInteger('default_attendant_user_id')
                    ->nullable()
                    ->after('added_by_user_id')
                    ->index();

                $table->foreign('default_attendant_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('contacts', 'skip_bot_to_default_attendant')) {
                $table->boolean('skip_bot_to_default_attendant')
                    ->default(false)
                    ->after('default_attendant_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'default_attendant_user_id')) {
                try {
                    $table->dropForeign(['default_attendant_user_id']);
                } catch (\Throwable) {
                    // Ignore if foreign key does not exist in legacy environments.
                }
                $table->dropColumn('default_attendant_user_id');
            }

            if (Schema::hasColumn('contacts', 'skip_bot_to_default_attendant')) {
                $table->dropColumn('skip_bot_to_default_attendant');
            }
        });
    }
};
