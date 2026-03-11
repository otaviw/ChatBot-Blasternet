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
        'whatsapp_message_id',
        'delivery_status',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'status_error',
        'status_meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'status_meta' => 'array',
        'media_size_bytes' => 'integer',
        'media_width' => 'integer',
        'media_height' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
