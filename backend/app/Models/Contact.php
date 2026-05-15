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
        'meta_number_id',
        'source',
        'added_by_user_id',
        'default_attendant_user_id',
        'skip_bot_to_default_attendant',
    ];

    protected $casts = [
        'last_interaction_at' => 'datetime',
        'skip_bot_to_default_attendant' => 'boolean',
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

    public function defaultAttendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_attendant_user_id');
    }

    public function metaNumber(): BelongsTo
    {
        return $this->belongsTo(CompanyMetaNumber::class, 'meta_number_id');
    }
}
