<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'ai_conversation_id',
        'user_id',
        'role',
        'content',
        'provider',
        'model',
        'response_time_ms',
        'raw_payload',
        'meta',
    ];

    protected $casts = [
        'response_time_ms' => 'integer',
        'raw_payload' => 'array',
        'meta' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
