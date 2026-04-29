<?php

namespace App\Services\Bot;

class AiSettingsPayloadBuilder
{
    private const BOOLEAN_FIELDS = [
        'ai_enabled',
        'ai_internal_chat_enabled',
        'ai_chatbot_enabled',
        'ai_chatbot_auto_reply_enabled',
        'ai_usage_enabled',
    ];

    private const NULLABLE_INTEGER_FIELDS = [
        'ai_usage_limit_monthly',
        'max_users',
        'max_conversation_messages_monthly',
        'max_template_messages_monthly',
        'ai_max_context_messages',
        'ai_max_response_tokens',
    ];

    private const PASSTHROUGH_FIELDS = [
        'ai_chatbot_rules',
        'ai_chatbot_mode',
        'ai_persona',
        'ai_tone',
        'ai_language',
        'ai_formality',
        'ai_system_prompt',
        'ai_temperature',
        'ai_provider',
        'ai_model',
    ];

    /**
     * @return array<int, string>
     */
    public function fieldNames(): array
    {
        return array_values(array_unique(array_merge(
            self::BOOLEAN_FIELDS,
            self::NULLABLE_INTEGER_FIELDS,
            self::PASSTHROUGH_FIELDS
        )));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $allowedFields
     * @return array<string, mixed>
     */
    public function fromValidated(array $validated, array $allowedFields): array
    {
        $payload = [];

        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $payload[$field] = $this->normalizeBoolean($validated[$field]);
                continue;
            }

            if (in_array($field, self::NULLABLE_INTEGER_FIELDS, true)) {
                $payload[$field] = $validated[$field] !== null
                    ? (int) $validated[$field]
                    : null;
                continue;
            }

            if (in_array($field, self::PASSTHROUGH_FIELDS, true)) {
                $payload[$field] = $validated[$field];
            }
        }

        return $payload;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }
}
