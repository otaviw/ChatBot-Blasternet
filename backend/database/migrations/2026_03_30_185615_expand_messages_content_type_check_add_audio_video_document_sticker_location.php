<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_content_type_check');

        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_content_type_check 
            CHECK (content_type IN (
                'text', 'image', 'video', 'audio', 'document', 'sticker', 'location', 'contacts'
            ))");
    }

    public function down(): void
    {
        // Reverte (só originais, sem novos)
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_content_type_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_content_type_check 
            CHECK (content_type IN ('text', 'image'))");  // Ajuste pros seus originais
    }
};