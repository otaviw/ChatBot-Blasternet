<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name',
        'type',
        'message',
        'template_id',
        'status',
        'company_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function campaignContacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }

    /** Adiciona contagens de status dos contatos da campanha à query. */
    public function scopeWithStatusCounts(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->withCount([
            'campaignContacts',
            'campaignContacts as sent_count'    => fn ($q) => $q->where('status', 'sent'),
            'campaignContacts as failed_count'  => fn ($q) => $q->where('status', 'failed'),
            'campaignContacts as skipped_count' => fn ($q) => $q->where('status', 'skipped'),
            'campaignContacts as pending_count' => fn ($q) => $q->where('status', 'pending'),
        ]);
    }
}
