<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

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

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function openedByUser()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function messages()
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function lastMessage()
    {
        return $this->hasOne(AiMessage::class)->latestOfMany('id');
    }
}
