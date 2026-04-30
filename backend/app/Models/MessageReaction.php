<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReaction extends Model
{
    protected $fillable = [
        'message_id',
        'whatsapp_message_id',
        'reactor_phone',
        'emoji',
        'reacted_at',
        'meta',
    ];

    protected $casts = [
        'reacted_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $hidden = [
        'message_id',
        'whatsapp_message_id',
        'meta',
        'created_at',
        'updated_at',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
