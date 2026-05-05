<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')
            ->whereNotNull('tags')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $conversation) {
                    $jsonTags = json_decode((string) $conversation->tags, true);
                    if (! is_array($jsonTags) || $jsonTags === []) {
                        continue;
                    }

                    foreach ($jsonTags as $rawName) {
                        $name = mb_strtolower(trim((string) $rawName));
                        if ($name === '') {
                            continue;
                        }

                        $tagId = DB::table('tags')
                            ->where('company_id', $conversation->company_id)
                            ->where('name', $name)
                            ->value('id');

                        if (! $tagId) {
                            $tagId = DB::table('tags')->insertGetId([
                                'company_id' => $conversation->company_id,
                                'name'       => $name,
                                'color'      => '#6b7280',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        DB::table('conversation_tag')->insertOrIgnore([
                            'conversation_id' => $conversation->id,
                            'tag_id'          => $tagId,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
    }
};
