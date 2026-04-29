<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    protected $fillable = [
        'name',
        'reseller_id',
        'meta_phone_number_id',
        'meta_waba_id',
        'meta_access_token',
    ];

    protected $hidden = [
        'meta_access_token',
        'meta_phone_number_id_hash',
    ];

    protected $appends = [
        'has_meta_credentials',
    ];

    protected $casts = [
        'meta_access_token' => 'encrypted',
        'meta_phone_number_id' => 'encrypted',
        'meta_waba_id' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if ($company->isDirty('meta_phone_number_id')) {
                $company->meta_phone_number_id_hash = self::phoneNumberIdHash($company->meta_phone_number_id);
            }
        });
    }

    public static function phoneNumberIdHash(?string $phoneNumberId): ?string
    {
        $normalized = trim((string) $phoneNumberId);
        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function botSetting(): HasOne
    {
        return $this->hasOne(CompanyBotSetting::class);
    }

    /** Se tem token e phone_number_id configurados (pode vir do .env ou do banco). */
    public function hasMetaCredentials(): bool
    {
        $token = $this->meta_access_token ?? config('whatsapp.access_token');
        $phoneId = $this->meta_phone_number_id ?? config('whatsapp.phone_number_id');

        return ! empty($token) && ! empty($phoneId);
    }

    public function getHasMetaCredentialsAttribute(): bool
    {
        return $this->hasMetaCredentials();
    }

    public function quickReplies(): HasMany
    {
        return $this->hasMany(QuickReply::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function aiKnowledgeEntries(): HasMany
    {
        return $this->hasMany(AiCompanyKnowledge::class);
    }

    public function appointmentSetting(): HasOne
    {
        return $this->hasOne(AppointmentSetting::class);
    }

    public function appointmentServices(): HasMany
    {
        return $this->hasMany(AppointmentService::class);
    }

    public function appointmentStaffProfiles(): HasMany
    {
        return $this->hasMany(AppointmentStaffProfile::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->latest('starts_at');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function scopeForReseller(Builder $query, ?int $resellerId): Builder
    {
        if ($resellerId === null) {
            return $query;
        }

        return $query->where('reseller_id', $resellerId);
    }
}
