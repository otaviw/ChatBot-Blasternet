<?php

declare(strict_types=1);


namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name',
        'phone',
        'last_interaction_at',
        'company_id',
        'source',
        'added_by_user_id',
    ];

    protected $casts = [
        'last_interaction_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
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
