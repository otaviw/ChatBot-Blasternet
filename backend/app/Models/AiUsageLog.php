<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    public const TYPE_INTERNAL_CHAT = 'internal_chat';
    public const TYPE_CHATBOT = 'chatbot';

    public const ALLOWED_TYPES = [
        self::TYPE_INTERNAL_CHAT,
        self::TYPE_CHATBOT,
    ];

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'type',
        'message_length',
        'tokens_used',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
        'message_length' => 'integer',
        'tokens_used' => 'integer',
        'created_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}

