<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSuggestionFeedback extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_suggestion_feedback';

    protected $fillable = [
        'suggestion_id',
        'user_id',
        'helpful',
        'reason',
    ];

    protected $casts = [
        'helpful'    => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
