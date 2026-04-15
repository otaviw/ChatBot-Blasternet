<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiAuditLog;
use App\Models\AiUsage;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\InternalAiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Ai\ToolCallingTestAiProvider;
use Tests\Fakes\Ai\UnknownToolTestAiProvider;
use Tests\TestCase;

class InternalAiChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_message_fails_when_company_ai_is_disabled(): void
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

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('IA interna não está habilitada para esta empresa.');

        $service->sendMessage($user, 'Teste');
    }

    public function test_send_message_fails_when_internal_ai_chat_is_disabled(): void
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

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('IA interna não está habilitada para esta empresa.');

        $service->sendMessage($user, 'Teste');
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

    public function test_send_message_executes_tool_and_generates_final_response_with_tool_result(): void
    {
        ToolCallingTestAiProvider::reset();
        config()->set('ai.provider', 'tool_calling_test');
        config()->set('ai.model', 'tool-model');
        config()->set('ai.provider_classes', [
            'tool_calling_test' => ToolCallingTestAiProvider::class,
            'null' => \App\Services\Ai\Providers\NullAiProvider::class,
        ]);

        $company = Company::create(['name' => 'Empresa AI Tool Call']);
        $user = $this->createCompanyUser($company, 'ai-tool-call@test.local');

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511988887777',
            'customer_name' => 'Cliente Tool',
            'status' => 'open',
            'bot_context' => ['plan' => 'Plano Tool'],
        ]);

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'tool_calling_test',
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Qual o plano do cliente 5511988887777?');

        $this->assertSame('get_customer_by_phone', $result['tool_call_request']['tool'] ?? null);
        $this->assertSame('+55 (11) 98888-7777', $result['tool_call_request']['params']['phone'] ?? null);
        $this->assertSame('executed', $result['assistant_message']->meta['tool_call_execution_status'] ?? null);
        $this->assertSame('get_customer_by_phone', $result['assistant_message']->meta['tool_used'] ?? null);
        $this->assertSame('Cliente Tool', $result['assistant_message']->meta['tool_result']['name'] ?? null);
        $this->assertSame('Plano Tool', $result['assistant_message']->meta['tool_result']['plan'] ?? null);
        $this->assertStringContainsString('Resposta final com ferramenta', (string) $result['assistant_message']->content);
        $this->assertSame(2, ToolCallingTestAiProvider::$calls);
        $this->assertDatabaseHas('ai_usages', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tool_used' => 'get_customer_by_phone',
        ]);
        $this->assertDatabaseHas('ai_audit_logs', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'action' => AiAuditLog::ACTION_TOOL_EXECUTED,
        ]);
    }

    public function test_send_message_falls_back_when_tool_is_unknown(): void
    {
        UnknownToolTestAiProvider::reset();
        config()->set('ai.provider', 'unknown_tool_test');
        config()->set('ai.model', 'tool-model');
        config()->set('ai.provider_classes', [
            'unknown_tool_test' => UnknownToolTestAiProvider::class,
            'null' => \App\Services\Ai\Providers\NullAiProvider::class,
        ]);

        $company = Company::create(['name' => 'Empresa AI Unknown Tool']);
        $user = $this->createCompanyUser($company, 'ai-unknown-tool@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'unknown_tool_test',
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Mensagem que gera tool desconhecida');

        $this->assertSame(
            'unknown_tool',
            $result['assistant_message']->meta['tool_call_execution_status'] ?? null
        );
        $this->assertSame('Resposta sem ferramenta.', (string) $result['assistant_message']->content);
        $this->assertSame(2, UnknownToolTestAiProvider::$calls);
        $this->assertDatabaseHas('ai_audit_logs', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'action' => AiAuditLog::ACTION_TOOL_FAILED,
        ]);
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
        $result = $service->sendMessage($user, 'Pergunta com provider inválido');

        $this->assertSame('test', $result['provider']);
        $this->assertStringContainsString('Pergunta com provider inválido', (string) $result['assistant_message']->content);
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
        $this->expectExceptionMessage('Conversa de IA não pertence ao usuário autenticado.');

        $service->sendMessage($otherUser, 'Mensagem indevida', $conversation);
    }

    public function test_send_message_fails_when_user_cannot_use_internal_ai(): void
    {
        $company = Company::create(['name' => 'Empresa AI Permission']);
        $user = $this->createCompanyUser($company, 'ai-no-permission@test.local', false, User::ROLE_AGENT);

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Usuário não possui permissão para usar IA interna.');

        $service->sendMessage($user, 'Mensagem sem permissão');
    }

    public function test_send_message_allows_company_admin_even_when_can_use_ai_is_false(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-TEST]');

        $company = Company::create(['name' => 'Empresa AI Admin']);
        $user = $this->createCompanyUser($company, 'ai-admin@test.local', false, User::ROLE_COMPANY_ADMIN);

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $service = $this->app->make(InternalAiChatService::class);
        $result = $service->sendMessage($user, 'Mensagem do admin');

        $this->assertSame('test', $result['provider']);
        $this->assertSame('assistant', (string) $result['assistant_message']->role);
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
        $this->expectExceptionMessage('Limite de uso de IA atingido');

        try {
            $service->sendMessage($user, 'Mensagem com limite atingido');
        } finally {
            $settings->refresh();

            $this->assertSame(1, (int) $settings->ai_usage_count);
            $this->assertDatabaseCount('ai_usage_logs', 0);
        }
    }

    public function test_send_message_blocks_when_ai_usage_is_disabled(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');

        $company = Company::create(['name' => 'Empresa AI Usage Disabled']);
        $user = $this->createCompanyUser($company, 'ai-usage-disabled@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_usage_enabled' => false,
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Uso de IA desabilitado para esta empresa.');

        $service->sendMessage($user, 'Mensagem bloqueada por uso desabilitado');
    }

    public function test_send_message_blocks_when_new_monthly_usage_limit_is_reached(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');

        $company = Company::create(['name' => 'Empresa AI New Monthly Limit']);
        $user = $this->createCompanyUser($company, 'ai-new-limit@test.local');

        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_usage_enabled' => true,
            'ai_usage_limit_monthly' => 1,
        ]);

        AiUsage::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'conversation_id' => null,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tokens_used' => null,
            'tool_used' => null,
            'created_at' => now(),
        ]);

        $service = $this->app->make(InternalAiChatService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Limite de uso de IA atingido');

        $service->sendMessage($user, 'Mensagem bloqueada por limite mensal novo');
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
        $this->assertDatabaseHas('ai_usages', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'feature' => AiUsage::FEATURE_INTERNAL_CHAT,
            'tool_used' => null,
        ]);
        $this->assertDatabaseHas('ai_audit_logs', [
            'company_id' => (int) $company->id,
            'user_id' => (int) $user->id,
            'conversation_id' => (int) $result['conversation']->id,
            'action' => AiAuditLog::ACTION_MESSAGE_SENT,
        ]);
    }

    private function createCompanyUser(
        Company $company,
        string $email,
        bool $canUseAi = true,
        string $role = User::ROLE_COMPANY_ADMIN
    ): User
    {
        return User::create([
            'name' => 'User AI',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => $canUseAi,
        ]);
    }
}
