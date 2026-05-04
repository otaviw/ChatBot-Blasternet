<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyBotSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_active'                           => ['required', 'boolean'],
            'ai_enabled'                          => ['sometimes', 'boolean'],
            'ai_internal_chat_enabled'            => ['sometimes', 'boolean'],
            'ai_chatbot_enabled'                  => ['sometimes', 'boolean'],
            'ai_chatbot_shadow_mode'              => ['sometimes', 'boolean'],
            'ai_chatbot_sandbox_enabled'          => ['sometimes', 'boolean'],
            'ai_chatbot_test_numbers'             => ['sometimes', 'nullable', 'array', 'max:200'],
            'ai_chatbot_test_numbers.*'           => ['string', 'max:40'],
            'ai_chatbot_confidence_threshold'     => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'ai_chatbot_handoff_repeat_limit'     => ['sometimes', 'integer', 'min:1', 'max:10'],
            'ai_chatbot_auto_reply_enabled'       => ['sometimes', 'boolean'],
            'ai_chatbot_rules'                    => ['nullable', 'array'],
            'ai_usage_enabled'                    => ['sometimes', 'boolean'],
            'ai_usage_limit_monthly'              => ['nullable', 'integer', 'min:1'],
            'max_users'                           => ['nullable', 'integer', 'min:1'],
            'max_conversation_messages_monthly'   => ['nullable', 'integer', 'min:1'],
            'max_template_messages_monthly'       => ['nullable', 'integer', 'min:1'],
            'timezone'                            => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'welcome_message'                     => ['nullable', 'string', 'max:2000'],
            'fallback_message'                    => ['nullable', 'string', 'max:2000'],
            'out_of_hours_message'                => ['nullable', 'string', 'max:2000'],
            'business_hours'                      => ['required', 'array'],
            'business_hours.*.enabled'            => ['required', 'boolean'],
            'business_hours.*.start'              => ['nullable', 'date_format:H:i'],
            'business_hours.*.end'                => ['nullable', 'date_format:H:i'],
            'keyword_replies'                     => ['nullable', 'array', 'max:200'],
            'keyword_replies.*.keyword'           => ['required_with:keyword_replies', 'string', 'max:120'],
            'keyword_replies.*.reply'             => ['required_with:keyword_replies', 'string', 'max:2000'],
            'service_areas'                       => ['nullable', 'array', 'max:50'],
            'service_areas.*'                     => ['string', 'max:120'],
            'stateful_menu_flow'                  => ['nullable', 'array'],
            'inactivity_close_hours'              => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }
}
