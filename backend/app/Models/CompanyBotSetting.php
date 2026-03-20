<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBotSetting extends Model
{
    protected $fillable = [
        'company_id',
        'is_active',
        'ai_enabled',
        'ai_internal_chat_enabled',
        'ai_chatbot_auto_reply_enabled',
        'ai_provider',
        'ai_model',
        'ai_system_prompt',
        'ai_temperature',
        'ai_max_response_tokens',
        'timezone',
        'welcome_message',
        'fallback_message',
        'out_of_hours_message',
        'business_hours',
        'keyword_replies',
        'service_areas',
        'stateful_menu_flow',
        'inactivity_close_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ai_enabled' => 'boolean',
        'ai_internal_chat_enabled' => 'boolean',
        'ai_chatbot_auto_reply_enabled' => 'boolean',
        'ai_temperature' => 'float',
        'ai_max_response_tokens' => 'integer',
        'business_hours' => 'array',
        'keyword_replies' => 'array',
        'service_areas' => 'array',
        'stateful_menu_flow' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
