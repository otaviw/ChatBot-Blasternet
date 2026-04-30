<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    use BelongsToCompany;
    // ── Tipos legados (campo `type`) ──────────────────────────────────────────
    public const TYPE_INTERNAL_CHAT = 'internal_chat';
    public const TYPE_CHATBOT = 'chatbot';

    public const ALLOWED_TYPES = [
        self::TYPE_INTERNAL_CHAT,
        self::TYPE_CHATBOT,
    ];

    // ── Features (campo `feature`) ────────────────────────────────────────────
    public const FEATURE_INTERNAL_CHAT = 'internal_chat';
    public const FEATURE_CONVERSATION_SUGGESTION = 'conversation_suggestion';
    public const FEATURE_CHATBOT = 'chatbot';

    public const ALLOWED_FEATURES = [
        self::FEATURE_INTERNAL_CHAT,
        self::FEATURE_CONVERSATION_SUGGESTION,
        self::FEATURE_CHATBOT,
    ];

    // ── Status ────────────────────────────────────────────────────────────────
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    // ── Tipos de erro normalizados ────────────────────────────────────────────
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const ERROR_VALIDATION = 'validation';
    public const ERROR_UNKNOWN = 'unknown';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'type',
        'provider',
        'model',
        'feature',
        'status',
        'message_length',
        'tokens_used',
        'response_time_ms',
        'error_type',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
        'message_length' => 'integer',
        'tokens_used' => 'integer',
        'response_time_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
