<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'company_id',
        'customer_phone',
        'status',
        'handling_mode',
        'assigned_user_id',
        'assumed_at',
    ];

    protected $casts = [
        'assumed_at' => 'datetime',
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
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function isManualMode(): bool
    {
        return $this->handling_mode === 'manual';
    }
}
