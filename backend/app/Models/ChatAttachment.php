<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAttachment extends Model
{
    protected $fillable = [
        'message_id',
        'disk_path',
        'url',
        'mime_type',
        'size_bytes',
        'original_name',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
