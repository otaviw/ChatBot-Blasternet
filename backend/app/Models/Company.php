<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'meta_phone_number_id',
        'meta_waba_id',
        'meta_access_token',
    ];

    protected $hidden = [
        'meta_access_token',
    ];

    protected $appends = [
        'has_meta_credentials',
    ];

    protected $casts = [
        'meta_access_token' => 'encrypted',
        'meta_waba_id' => 'encrypted',
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

    public function quickReplies()
    {
        return $this->hasMany(QuickReply::class);
    }

    public function areas()
    {
        return $this->hasMany(Area::class);
    }

    public function aiConversations()
    {
        return $this->hasMany(AiConversation::class);
    }

    public function aiKnowledgeEntries()
    {
        return $this->hasMany(AiCompanyKnowledge::class);
    }

    public function appointmentSetting()
    {
        return $this->hasOne(AppointmentSetting::class);
    }

    public function appointmentServices()
    {
        return $this->hasMany(AppointmentService::class);
    }

    public function appointmentStaffProfiles()
    {
        return $this->hasMany(AppointmentStaffProfile::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class)->latest('starts_at');
    }
}
