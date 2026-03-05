<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'content_type')) {
                $table->enum('content_type', ['text', 'image'])
                    ->default('text')
                    ->after('type');
            }

            if (! Schema::hasColumn('messages', 'media_provider')) {
                $table->string('media_provider', 40)->nullable()->after('text');
            }

            if (! Schema::hasColumn('messages', 'media_key')) {
                $table->string('media_key', 255)->nullable()->after('media_provider');
            }

            if (! Schema::hasColumn('messages', 'media_url')) {
                $table->text('media_url')->nullable()->after('media_key');
            }

            if (! Schema::hasColumn('messages', 'media_mime_type')) {
                $table->string('media_mime_type', 120)->nullable()->after('media_url');
            }

            if (! Schema::hasColumn('messages', 'media_size_bytes')) {
                $table->unsignedBigInteger('media_size_bytes')->nullable()->after('media_mime_type');
            }

            if (! Schema::hasColumn('messages', 'media_width')) {
                $table->unsignedInteger('media_width')->nullable()->after('media_size_bytes');
            }

            if (! Schema::hasColumn('messages', 'media_height')) {
                $table->unsignedInteger('media_height')->nullable()->after('media_width');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->text('text')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('messages')
            ->whereNull('text')
            ->update(['text' => '']);

        Schema::table('messages', function (Blueprint $table) {
            $table->text('text')->nullable(false)->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'media_height')) {
                $table->dropColumn('media_height');
            }
            if (Schema::hasColumn('messages', 'media_width')) {
                $table->dropColumn('media_width');
            }
            if (Schema::hasColumn('messages', 'media_size_bytes')) {
                $table->dropColumn('media_size_bytes');
            }
            if (Schema::hasColumn('messages', 'media_mime_type')) {
                $table->dropColumn('media_mime_type');
            }
            if (Schema::hasColumn('messages', 'media_url')) {
                $table->dropColumn('media_url');
            }
            if (Schema::hasColumn('messages', 'media_key')) {
                $table->dropColumn('media_key');
            }
            if (Schema::hasColumn('messages', 'media_provider')) {
                $table->dropColumn('media_provider');
            }
            if (Schema::hasColumn('messages', 'content_type')) {
                $table->dropColumn('content_type');
            }
        });
    }
};
