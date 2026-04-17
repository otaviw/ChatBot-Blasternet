<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'last_interaction_at',
        'company_id',
    ];

    protected $casts = [
        'last_interaction_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function campaignContacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }

    public function isWithin24h(): bool
    {
        return $this->last_interaction_at !== null
            && $this->last_interaction_at->gt(now()->subHours(24));
    }
}
