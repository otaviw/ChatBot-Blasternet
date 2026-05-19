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
            if (! Schema::hasColumn('companies', 'meta_app_secret')) {
                $table->text('meta_app_secret')->nullable()->after('meta_access_token');
            }

            if (! Schema::hasColumn('companies', 'meta_verify_token')) {
                $table->string('meta_verify_token', 255)->nullable()->after('meta_app_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'meta_verify_token')) {
                $table->dropColumn('meta_verify_token');
            }

            if (Schema::hasColumn('companies', 'meta_app_secret')) {
                $table->dropColumn('meta_app_secret');
            }
        });
    }
};

