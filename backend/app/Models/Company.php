<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Company extends Model
{
    protected $fillable = [
        'name',
        'meta_phone_number_id',
        'meta_access_token',
    ];

    protected $hidden = [
        'meta_access_token',
    ];

    protected $appends = [
        'has_meta_credentials',
    ];

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function botSetting()
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

    /**
     * Backward-compatible read: accepts legacy plaintext values already persisted.
     */
    public function getMetaAccessTokenAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    public function setMetaAccessTokenAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['meta_access_token'] = null;

            return;
        }

        try {
            Crypt::decryptString((string) $value);
            $this->attributes['meta_access_token'] = (string) $value;
        } catch (DecryptException) {
            $this->attributes['meta_access_token'] = Crypt::encryptString((string) $value);
        }
    }

    public function quickReplies()
    {
        return $this->hasMany(QuickReply::class);
    }
}
