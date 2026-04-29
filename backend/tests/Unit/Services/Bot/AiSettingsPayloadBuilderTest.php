<?php

namespace Tests\Unit\Services\Bot;

use App\Services\Bot\AiSettingsPayloadBuilder;
use PHPUnit\Framework\TestCase;

class AiSettingsPayloadBuilderTest extends TestCase
{
    public function test_from_validated_returns_only_allowed_and_casted_fields(): void
    {
        $builder = new AiSettingsPayloadBuilder();

        $validated = [
            'ai_enabled' => 1,
            'ai_usage_limit_monthly' => '1200',
            'ai_chatbot_rules' => ['foo' => 'bar'],
            'max_users' => null,
            'unknown_field' => 'ignored',
        ];

        $payload = $builder->fromValidated($validated, [
            'ai_enabled',
            'ai_usage_limit_monthly',
            'ai_chatbot_rules',
            'max_users',
        ]);

        $this->assertSame([
            'ai_enabled' => true,
            'ai_usage_limit_monthly' => 1200,
            'ai_chatbot_rules' => ['foo' => 'bar'],
            'max_users' => null,
        ], $payload);
    }

    public function test_from_validated_ignores_fields_not_present_in_payload(): void
    {
        $builder = new AiSettingsPayloadBuilder();

        $payload = $builder->fromValidated([
            'ai_enabled' => false,
        ], [
            'ai_enabled',
            'ai_internal_chat_enabled',
        ]);

        $this->assertSame([
            'ai_enabled' => false,
        ], $payload);
    }

    public function test_from_validated_ignores_allowed_field_when_type_is_unknown(): void
    {
        $builder = new AiSettingsPayloadBuilder();

        $payload = $builder->fromValidated([
            'custom_ai_field' => 'value',
        ], [
            'custom_ai_field',
        ]);

        $this->assertSame([], $payload);
    }

    public function test_from_validated_keeps_zero_for_nullable_integer_field(): void
    {
        $builder = new AiSettingsPayloadBuilder();

        $payload = $builder->fromValidated([
            'ai_usage_limit_monthly' => 0,
        ], [
            'ai_usage_limit_monthly',
        ]);

        $this->assertSame([
            'ai_usage_limit_monthly' => 0,
        ], $payload);
    }

    public function test_from_validated_casts_string_false_to_boolean_false(): void
    {
        $builder = new AiSettingsPayloadBuilder();

        $payload = $builder->fromValidated([
            'ai_enabled' => 'false',
        ], [
            'ai_enabled',
        ]);

        $this->assertSame([
            'ai_enabled' => false,
        ], $payload);
    }
}
