<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiConversation extends Model
{
    use BelongsToCompany;
    public const ORIGIN_INTERNAL_CHAT = 'internal_chat';
    public const ORIGIN_CHATBOT = 'chatbot';

    protected $fillable = [
        'company_id',
        'opened_by_user_id',
        'origin',
        'title',
        'meta',
        'last_message_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(AiMessage::class)->latestOfMany('id');
    }
}
