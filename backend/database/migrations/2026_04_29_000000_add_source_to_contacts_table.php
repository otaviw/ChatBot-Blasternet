<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('source')->nullable()->after('company_id');           // 'manual' | 'csv'
            $table->unsignedBigInteger('added_by_user_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['source', 'added_by_user_id']);
        });
    }
};
