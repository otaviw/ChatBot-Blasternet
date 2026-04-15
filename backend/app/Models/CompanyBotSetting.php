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
        'ai_usage_enabled',
        'ai_usage_limit_monthly',
        'ai_chatbot_enabled',
        'ai_chatbot_auto_reply_enabled',
        'ai_chatbot_rules',
        'ai_persona',
        'ai_tone',
        'ai_language',
        'ai_formality',
        'ai_max_context_messages',
        'ai_monthly_limit',
        'ai_usage_count',
        'ai_chatbot_mode',
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
        'unattended_alert_hours',
        'max_users',
        'max_conversation_messages_monthly',
        'max_template_messages_monthly',
        'conversation_messages_used',
        'template_messages_used',
        'usage_reset_month',
        'usage_reset_year',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ai_enabled' => 'boolean',
        'ai_internal_chat_enabled' => 'boolean',
        'ai_usage_enabled' => 'boolean',
        'ai_usage_limit_monthly' => 'integer',
        'ai_chatbot_enabled' => 'boolean',
        'ai_chatbot_auto_reply_enabled' => 'boolean',
        'ai_chatbot_rules' => 'array',
        'ai_max_context_messages' => 'integer',
        'ai_monthly_limit' => 'integer',
        'ai_usage_count' => 'integer',
        'ai_temperature' => 'float',
        'ai_max_response_tokens' => 'integer',
        'business_hours' => 'array',
        'keyword_replies' => 'array',
        'service_areas' => 'array',
        'stateful_menu_flow' => 'array',
        'inactivity_close_hours' => 'integer',
        'unattended_alert_hours' => 'integer',
        'max_users' => 'integer',
        'max_conversation_messages_monthly' => 'integer',
        'max_template_messages_monthly' => 'integer',
        'conversation_messages_used' => 'integer',
        'template_messages_used' => 'integer',
        'usage_reset_month' => 'integer',
        'usage_reset_year' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
