<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBotSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meta_phone_number_id'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_access_token'             => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active'                     => ['required', 'boolean'],
            'timezone'                      => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'welcome_message'               => ['nullable', 'string', 'max:2000'],
            'fallback_message'              => ['nullable', 'string', 'max:2000'],
            'out_of_hours_message'          => ['nullable', 'string', 'max:2000'],
            'business_hours'                => ['required', 'array'],
            'business_hours.*.enabled'      => ['required', 'boolean'],
            'business_hours.*.start'        => ['nullable', 'date_format:H:i'],
            'business_hours.*.end'          => ['nullable', 'date_format:H:i'],
            'keyword_replies'               => ['nullable', 'array', 'max:200'],
            'keyword_replies.*.keyword'     => ['required_with:keyword_replies', 'string', 'max:120'],
            'keyword_replies.*.reply'       => ['required_with:keyword_replies', 'string', 'max:2000'],
            'service_areas'                 => ['nullable', 'array', 'max:50'],
            'service_areas.*'               => ['string', 'max:120'],
            'stateful_menu_flow'            => ['nullable', 'array'],
            'inactivity_close_hours'        => ['nullable', 'integer', 'min:1', 'max:720'],
            'message_retention_days'        => ['sometimes', 'nullable', 'integer', 'min:1', 'max:180'],
            'ai_enabled'                    => ['sometimes', 'boolean'],
            'ai_internal_chat_enabled'      => ['sometimes', 'boolean'],
            'ai_usage_enabled'              => ['sometimes', 'boolean'],
            'ai_usage_limit_monthly'        => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_chatbot_enabled'            => ['sometimes', 'boolean'],
            'ai_chatbot_auto_reply_enabled' => ['sometimes', 'boolean'],
            'ai_chatbot_mode'               => ['sometimes', 'nullable', 'string', Rule::in(['disabled', 'always', 'fallback', 'outside_business_hours'])],
            'ai_persona'                    => ['sometimes', 'nullable', 'string', 'max:500'],
            'ai_tone'                       => ['sometimes', 'nullable', 'string', 'max:120'],
            'ai_language'                   => ['sometimes', 'nullable', 'string', 'max:50'],
            'ai_formality'                  => ['sometimes', 'nullable', 'string', 'max:50'],
            'ai_system_prompt'              => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_max_context_messages'       => ['sometimes', 'nullable', 'integer', 'min:10', 'max:20'],
            'ai_temperature'                => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'ai_max_response_tokens'        => ['sometimes', 'nullable', 'integer', 'min:64', 'max:4096'],
            'ai_provider'                   => ['sometimes', 'nullable', 'string', 'max:60'],
            'ai_model'                      => ['sometimes', 'nullable', 'string', 'max:120'],
            'ai_chatbot_rules'              => ['sometimes', 'nullable', 'array', 'max:50'],
            'ai_chatbot_rules.*'            => ['string', 'max:500'],
        ];
    }
}
