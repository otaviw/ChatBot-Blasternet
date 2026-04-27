<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AiAuditLog extends Model
{
    use BelongsToCompany;
    public const ACTION_MESSAGE_SENT = 'message_sent';
    public const ACTION_TOOL_EXECUTED = 'tool_executed';
    public const ACTION_TOOL_FAILED = 'tool_failed';
    public const ACTION_SAFETY_BLOCKED = 'safety_blocked';

    public const ALLOWED_ACTIONS = [
        self::ACTION_MESSAGE_SENT,
        self::ACTION_TOOL_EXECUTED,
        self::ACTION_TOOL_FAILED,
        self::ACTION_SAFETY_BLOCKED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
        'metadata' => 'array',
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

