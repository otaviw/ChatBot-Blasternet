<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'joined_at',
        'last_read_at',
    ];

    protected $casts = [
        'joined_at'    => 'datetime',
        'last_read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class);
    }
}