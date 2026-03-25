<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\InternalAiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InternalAiChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_message_allows_company_ai_disabled_during_internal_chat_testing(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-TEST]');

        $company = Company::create(['name' => 'Empresa AI Off']);
        $user = $this->createCompanyUser($company, 'ai-off@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => true,
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Teste');

        $this->assertSame('test', $result['provider']);
        $this->assertSame('assistant', (string) $result['assistant_message']->role);
        $this->assertStringContainsString('Teste', (string) $result['assistant_message']->content);
    }

    public function test_send_message_allows_internal_ai_chat_disabled_during_internal_chat_testing(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-TEST]');

        $company = Company::create(['name' => 'Empresa AI Internal Off']);
        $user = $this->createCompanyUser($company, 'ai-internal-off@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => false,
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Teste');

        $this->assertSame('test', $result['provider']);
        $this->assertSame('assistant', (string) $result['assistant_message']->role);
        $this->assertStringContainsString('Teste', (string) $result['assistant_message']->content);
    }

    public function test_send_message_persists_user_and_assistant_messages(): void
    {
        config()->set('ai.provider', 'null');
        config()->set('ai.model', 'global-model');
        config()->set('ai.system_prompt', 'Prompt global');
        config()->set('ai.history_messages_limit', 10);
        config()->set('ai.providers.test.reply_prefix', '[TEST-AI]');

        $company = Company::create(['name' => 'Empresa AI Service']);
        $user = $this->createCompanyUser($company, 'ai-service@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_model' => 'company-model',
            'ai_system_prompt' => 'Prompt da empresa',
            'ai_temperature' => 0.45,
            'ai_max_response_tokens' => 220,
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Primeira pergunta');

        $this->assertSame('test', $result['provider']);
        $this->assertSame('company-model', $result['model']);
        $this->assertSame((int) $company->id, (int) $result['conversation']->company_id);
        $this->assertSame('internal_chat', (string) $result['conversation']->origin);
        $this->assertSame('user', (string) $result['user_message']->role);
        $this->assertSame('assistant', (string) $result['assistant_message']->role);
        $this->assertStringContainsString('Primeira pergunta', (string) $result['assistant_message']->content);

        $second = $service->sendMessage($user, 'Segunda pergunta', $result['conversation']);
        $this->assertSame((int) $result['conversation']->id, (int) $second['conversation']->id);
        $this->assertStringContainsString('Segunda pergunta', (string) $second['assistant_message']->content);

        $conversation = AiConversation::query()
            ->whereKey((int) $result['conversation']->id)
            ->with('messages')
            ->firstOrFail();

        $this->assertCount(4, $conversation->messages);
    }

    public function test_send_message_falls_back_to_global_provider_and_model_when_company_values_are_empty(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'global-model-fallback');
        config()->set('ai.providers.test.reply_prefix', '[GLOBAL]');

        $company = Company::create(['name' => 'Empresa AI Fallback']);
        $user = $this->createCompanyUser($company, 'ai-fallback@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => null,
            'ai_model' => null,
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Pergunta com fallback');

        $this->assertSame('test', $result['provider']);
        $this->assertSame('global-model-fallback', $result['model']);
        $this->assertSame('test', $result['assistant_message']->provider);
        $this->assertSame('global-model-fallback', $result['assistant_message']->model);
    }

    public function test_send_message_uses_global_provider_when_company_provider_is_invalid(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'global-model');
        config()->set('ai.providers.test.reply_prefix', '[GLOBAL-INVALID]');

        $company = Company::create(['name' => 'Empresa AI Invalid Provider']);
        $user = $this->createCompanyUser($company, 'ai-invalid-provider@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'provider_nao_existente',
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Pergunta com provider invalido');

        $this->assertSame('test', $result['provider']);
        $this->assertStringContainsString('Pergunta com provider invalido', (string) $result['assistant_message']->content);
    }

    public function test_send_message_fails_when_conversation_belongs_to_another_internal_user(): void
    {
        $company = Company::create(['name' => 'Empresa AI Owner']);
        $owner = $this->createCompanyUser($company, 'ai-owner@test.local');
        $otherUser = $this->createCompanyUser($company, 'ai-other-user@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $conversation = AiConversation::query()->create([
            'company_id' => (int) $company->id,
            'opened_by_user_id' => (int) $owner->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
            'title' => 'Thread privada',
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conversa de IA nao pertence ao usuario autenticado.');

        $service->sendMessage($otherUser, 'Mensagem indevida', $conversation);
    }

    public function test_send_message_fails_when_user_cannot_use_internal_ai(): void
    {
        $company = Company::create(['name' => 'Empresa AI Permission']);
        $user = $this->createCompanyUser($company, 'ai-no-permission@test.local', false);

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Usuario nao possui permissao para usar IA interna.');

        $service->sendMessage($user, 'Mensagem sem permissao');
    }

    public function test_send_message_blocks_when_company_monthly_limit_is_reached(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');

        $company = Company::create(['name' => 'Empresa AI Monthly Limit']);
        $user = $this->createCompanyUser($company, 'ai-limit@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_monthly_limit' => 1,
            'ai_usage_count' => 1,
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Limite mensal de uso de IA da empresa atingido.');

        try {
            $service->sendMessage($user, 'Mensagem com limite atingido');
        } finally {
            $settings->refresh();

            $this->assertSame(1, (int) $settings->ai_usage_count);
            $this->assertDatabaseCount('ai_usage_logs', 0);
        }
    }

    public function test_send_message_increments_usage_count_and_creates_audit_log(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-TEST]');

        $company = Company::create(['name' => 'Empresa AI Usage Count']);
        $user = $this->createCompanyUser($company, 'ai-usage-count@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_monthly_limit' => 10,
            'ai_usage_count' => 0,
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $content = 'Mensagem para auditoria';
        $result = $service->sendMessage($user, $content);

        $settings->refresh();

        $this->assertSame(1, (int) $settings->ai_usage_count);
        $this->assertDatabaseHas('ai_usage_logs', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
            'message_length' => mb_strlen($content),
        ]);
    }

    private function createCompanyUser(Company $company, string $email, bool $canUseAi = true): User
    {
        return User::create([
            'name' => 'User AI',
            'email' => $email,
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => $canUseAi,
        ]);
    }
}
