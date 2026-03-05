<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'direction',
        'type',
        'content_type',
        'text',
        'media_provider',
        'media_key',
        'media_url',
        'media_mime_type',
        'media_size_bytes',
        'media_width',
        'media_height',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'media_size_bytes' => 'integer',
        'media_width' => 'integer',
        'media_height' => 'integer',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
