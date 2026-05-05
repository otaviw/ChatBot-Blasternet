<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('ixc_base_url', 500)->nullable()->after('meta_access_token');
            $table->text('ixc_api_token')->nullable()->after('ixc_base_url');
            $table->boolean('ixc_self_signed')->default(false)->after('ixc_api_token');
            $table->unsignedSmallInteger('ixc_timeout_seconds')->default(15)->after('ixc_self_signed');
            $table->boolean('ixc_enabled')->default(false)->after('ixc_timeout_seconds');
            $table->timestamp('ixc_last_validated_at')->nullable()->after('ixc_enabled');
            $table->text('ixc_last_validation_error')->nullable()->after('ixc_last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'ixc_base_url',
                'ixc_api_token',
                'ixc_self_signed',
                'ixc_timeout_seconds',
                'ixc_enabled',
                'ixc_last_validated_at',
                'ixc_last_validation_error',
            ]);
        });
    }
};
