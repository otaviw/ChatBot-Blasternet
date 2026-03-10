<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'type',
        'content',
        'metadata',
        'edited_at',
        'deleted_at',
    ];

    protected $casts = [
        'metadata'  => 'array',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }
}
