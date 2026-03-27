<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    public const FEATURE_INTERNAL_CHAT = 'internal_chat';
    public const FEATURE_CHATBOT_FUTURE = 'chatbot_future';

    public const ALLOWED_FEATURES = [
        self::FEATURE_INTERNAL_CHAT,
        self::FEATURE_CHATBOT_FUTURE,
    ];

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'feature',
        'tokens_used',
        'tool_used',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
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

