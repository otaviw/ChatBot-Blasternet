<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBotSetting extends Model
{
    protected $fillable = [
        'company_id',
        'is_active',
        'timezone',
        'welcome_message',
        'fallback_message',
        'out_of_hours_message',
        'business_hours',
        'keyword_replies',
        'inactivity_close_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'business_hours' => 'array',
        'keyword_replies' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

