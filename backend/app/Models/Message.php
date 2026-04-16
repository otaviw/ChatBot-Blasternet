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
        'media_filename',
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

    protected $appends = ['sender_name'];

    public function getSenderNameAttribute(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $name = $meta['actor_user_name'] ?? null;
        return ($name !== null && $name !== '') ? (string) $name : null;
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }
}
