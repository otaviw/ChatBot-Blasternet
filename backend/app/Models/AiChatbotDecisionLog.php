<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatbotDecisionLog extends Model
{
    use BelongsToCompany;

    public const MODE_OFF = 'off';
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_SHADOW = 'shadow';
    public const MODE_ACTIVE = 'active';

    protected $fillable = [
        'company_id',
        'conversation_id',
        'message_id',
        'user_id',
        'mode',
        'gate_result',
        'intent',
        'confidence',
        'action',
        'handoff_reason',
        'used_knowledge',
        'knowledge_refs',
        'latency_ms',
        'tokens_used',
        'provider',
        'model',
        'error',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'user_id' => 'integer',
        'gate_result' => 'array',
        'confidence' => 'float',
        'used_knowledge' => 'boolean',
        'knowledge_refs' => 'array',
        'latency_ms' => 'integer',
        'tokens_used' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
