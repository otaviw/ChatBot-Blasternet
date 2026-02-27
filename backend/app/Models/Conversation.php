<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'company_id',
        'customer_phone',
        'status',
        'assigned_type',
        'assigned_id',
        'current_area_id',
        'handling_mode',
        'assigned_user_id',
        'assigned_area',
        'assumed_at',
        'closed_at',
        'bot_flow',
        'bot_step',
        'bot_context',
        'bot_last_interaction_at',
        'tags',
    ];

    protected $casts = [
        'assumed_at' => 'datetime',
        'closed_at' => 'datetime',
        'bot_last_interaction_at' => 'datetime',
        'bot_context' => 'array',
        'tags' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_id');
    }

    public function assignedArea()
    {
        return $this->belongsTo(Area::class, 'assigned_id');
    }

    public function currentArea()
    {
        return $this->belongsTo(Area::class, 'current_area_id');
    }

    public function transferHistory()
    {
        return $this->hasMany(ConversationTransfer::class)->latest('id');
    }

    public function isManualMode(): bool
    {
        return $this->handling_mode === 'human';
    }
}
