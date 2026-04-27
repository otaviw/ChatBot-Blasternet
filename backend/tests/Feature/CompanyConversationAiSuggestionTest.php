<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\AiUsageLog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyConversationAiSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_generate_ai_suggestion_for_conversation_without_saving_message(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-SUG]');

        $company = Company::create(['name' => 'Empresa Sugestão OK']);
        $user = $this->createCompanyUser($company, 'suggest-ok@test.local', true);
        $conversation = $this->createConversation($company);
        $this->createAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_max_context_messages' => 10,
            'ai_persona' => 'Assistente de atendimento',
            'ai_tone' => 'Claro',
            'ai_language' => 'pt-BR',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => 'text',
            'text' => 'Ola, como posso ajudar?',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Como esta meu pedido?',
        ]);

        $beforeCount = (int) Message::query()
            ->where('conversation_id', $conversation->id)
            ->count();

        $response = $this->actingAs($user)
            ->postJson("/api/minha-conta/conversas/{$conversation->id}/ia/sugestao");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('suggestion', '[AI-SUG] Como esta meu pedido?');

        $afterCount = (int) Message::query()
            ->where('conversation_id', $conversation->id)
            ->count();

        $this->assertSame($beforeCount, $afterCount);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseHas('ai_usage_logs', [
            'company_id' => $company->id,
            'conversation_id' => null,
            'feature' => AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            'status' => AiUsageLog::STATUS_OK,
        ]);
    }

    public function test_ai_suggestion_returns_422_when_user_has_no_permission(): void
    {
        config()->set('ai.provider', 'test');

        $company = Company::create(['name' => 'Empresa Sugestão Sem Permissão']);
        $user = $this->createCompanyUser($company, 'suggest-no-permission@test.local', false, User::ROLE_AGENT);
        $conversation = $this->createConversation($company);
        $this->createAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/minha-conta/conversas/{$conversation->id}/ia/sugestao");

        $response->assertStatus(422);
        $response->assertJsonPath('errors.user.0', 'Usuário não possui permissão para usar IA interna.');
    }

    public function test_ai_suggestion_returns_422_when_company_ai_is_disabled(): void
    {
        config()->set('ai.provider', 'test');

        $company = Company::create(['name' => 'Empresa Sugestão IA Off']);
        $user = $this->createCompanyUser($company, 'suggest-company-off@test.local', true);
        $conversation = $this->createConversation($company);
        $this->createAiSettings($company, [
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/minha-conta/conversas/{$conversation->id}/ia/sugestao");

        $response->assertStatus(422);
        $response->assertJsonPath('errors.ai.0', 'IA interna não está habilitada para esta empresa.');
    }

    public function test_company_admin_can_generate_suggestion_even_when_can_use_ai_is_false(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.providers.test.reply_prefix', '[AI-ADMIN]');

        $company = Company::create(['name' => 'Empresa Sugestão Admin']);
        $user = $this->createCompanyUser($company, 'suggest-admin@test.local', false, User::ROLE_COMPANY_ADMIN);
        $conversation = $this->createConversation($company);
        $this->createAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Preciso do status do pedido',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/minha-conta/conversas/{$conversation->id}/ia/sugestao");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('suggestion', '[AI-ADMIN] Preciso do status do pedido');
    }

    public function test_ai_suggestion_respects_company_context_messages_limit(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-LIMIT]');

        $company = Company::create(['name' => 'Empresa Sugestão Limite']);
        $user = $this->createCompanyUser($company, 'suggest-limit@test.local', true);
        $conversation = $this->createConversation($company);
        $this->createAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_max_context_messages' => 1,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => 'Mensagem do cliente fora da janela',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => 'text',
            'text' => 'Ultima mensagem do atendente',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/minha-conta/conversas/{$conversation->id}/ia/sugestao");

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('suggestion', '[AI-LIMIT]');
    }

    public function test_ai_suggestion_does_not_allow_access_to_other_company_conversation(): void
    {
        config()->set('ai.provider', 'test');

        $companyA = Company::create(['name' => 'Empresa Sugestão A']);
        $companyB = Company::create(['name' => 'Empresa Sugestão B']);

        $userA = $this->createCompanyUser($companyA, 'suggest-company-a@test.local', true);
        $conversationB = $this->createConversation($companyB);

        $this->createAiSettings($companyA, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);
        $this->createAiSettings($companyB, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $response = $this->actingAs($userA)
            ->postJson("/api/minha-conta/conversas/{$conversationB->id}/ia/sugestao");

        $response->assertStatus(404);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAiSettings(Company $company, array $overrides = []): CompanyBotSetting
    {
        return CompanyBotSetting::create(array_merge([
            'company_id' => $company->id,
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => false,
            'ai_provider' => null,
            'ai_max_context_messages' => 10,
        ], $overrides));
    }

    private function createConversation(Company $company): Conversation
    {
        return Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => $this->nextPhone(),
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'assigned_id' => null,
            'current_area_id' => null,
            'handling_mode' => 'bot',
            'assigned_user_id' => null,
            'assigned_area' => null,
            'assumed_at' => null,
            'closed_at' => null,
        ]);
    }

    private function createCompanyUser(
        Company $company,
        string $email,
        bool $canUseAi,
        string $role = User::ROLE_COMPANY_ADMIN
    ): User
    {
        return User::create([
            'name' => 'Operador IA Sugestão',
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => $canUseAi,
        ]);
    }

    private function nextPhone(): string
    {
        static $phone = 5511988800000;
        $phone++;

        return (string) $phone;
    }
}
