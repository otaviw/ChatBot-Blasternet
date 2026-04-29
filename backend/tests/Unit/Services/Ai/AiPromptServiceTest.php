<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPromptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_prompt_uses_template_for_current_environment_and_persists_history(): void
    {
        config()->set('ai_prompts.environment', 'dev');
        config()->set('ai_prompts.logs_enabled', false);
        config()->set('ai_prompts.history_enabled', true);
        config()->set('ai_prompts.templates', [
            'internal_chat.system' => [
                'version' => 'v7',
                'environments' => [
                    'dev' => 'Prompt interno DEV',
                    'prod' => 'Prompt interno PROD',
                ],
            ],
        ]);

        $company = Company::create(['name' => 'Empresa Prompt']);
        $user = User::create([
            'name' => 'User Prompt',
            'email' => 'prompt-service@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => true,
        ]);
        $conversation = AiConversation::create([
            'company_id' => (int) $company->id,
            'opened_by_user_id' => (int) $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        $service = $this->app->make(AiPromptService::class);
        $resolved = $service->resolvePrompt(
            templateKey: 'internal_chat.system',
            legacyFallbackText: 'Prompt legado',
            companyId: (int) $company->id,
            userId: (int) $user->id,
            conversationId: (int) $conversation->id,
            providerRequested: 'test',
            providerResolved: 'test',
            metadata: ['feature' => 'internal_chat']
        );

        $this->assertSame('internal_chat.system', $resolved['key']);
        $this->assertSame('v7', $resolved['version']);
        $this->assertSame('dev', $resolved['environment']);
        $this->assertSame('Prompt interno DEV', $resolved['content']);
        $this->assertFalse($resolved['fallback_used']);
        $this->assertSame('template', $resolved['source']);

        $this->assertDatabaseHas('ai_prompt_histories', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $conversation->id,
            'prompt_key' => 'internal_chat.system',
            'prompt_version' => 'v7',
            'prompt_environment' => 'dev',
            'fallback_used' => false,
            'provider_requested' => 'test',
            'provider_resolved' => 'test',
        ]);
    }

    public function test_resolve_prompt_uses_template_fallback_chain(): void
    {
        config()->set('ai_prompts.environment', 'dev');
        config()->set('ai_prompts.logs_enabled', false);
        config()->set('ai_prompts.history_enabled', false);
        config()->set('ai_prompts.templates', [
            'shared.default' => [
                'version' => 'v3',
                'environments' => [
                    'dev' => 'Prompt compartilhado DEV',
                    'prod' => '',
                ],
            ],
            'internal_chat.system' => [
                'version' => 'v9',
                'fallback' => 'shared.default',
                'environments' => [
                    'dev' => '',
                    'prod' => '',
                ],
            ],
        ]);

        $service = $this->app->make(AiPromptService::class);
        $resolved = $service->resolvePrompt(
            templateKey: 'internal_chat.system',
            legacyFallbackText: 'Prompt legado'
        );

        $this->assertSame('shared.default', $resolved['key']);
        $this->assertSame('v3', $resolved['version']);
        $this->assertSame('Prompt compartilhado DEV', $resolved['content']);
        $this->assertTrue($resolved['fallback_used']);
        $this->assertSame('template', $resolved['source']);
    }

    public function test_resolve_prompt_falls_back_to_legacy_text_when_templates_are_empty(): void
    {
        config()->set('ai_prompts.environment', 'prod');
        config()->set('ai_prompts.logs_enabled', false);
        config()->set('ai_prompts.history_enabled', false);
        config()->set('ai_prompts.templates', [
            'shared.default' => [
                'version' => 'v1',
                'environments' => [
                    'dev' => '',
                    'prod' => '',
                ],
            ],
            'conversation_suggestion.system' => [
                'version' => 'v2',
                'fallback' => 'shared.default',
                'environments' => [
                    'dev' => '',
                    'prod' => '',
                ],
            ],
        ]);

        $service = $this->app->make(AiPromptService::class);
        $resolved = $service->resolvePrompt(
            templateKey: 'conversation_suggestion.system',
            legacyFallbackText: 'Prompt legado global'
        );

        $this->assertSame('conversation_suggestion.system', $resolved['key']);
        $this->assertSame('prod', $resolved['environment']);
        $this->assertSame('Prompt legado global', $resolved['content']);
        $this->assertTrue($resolved['fallback_used']);
        $this->assertSame('legacy_fallback', $resolved['source']);
    }
}
