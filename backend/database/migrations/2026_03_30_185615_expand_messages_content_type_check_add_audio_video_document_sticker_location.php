<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_content_type_check');

        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_content_type_check
            CHECK (content_type IN (
                'text', 'image', 'video', 'audio', 'document', 'sticker', 'location', 'contacts'
            ))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_content_type_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_content_type_check
            CHECK (content_type IN ('text', 'image'))");
    }
};
