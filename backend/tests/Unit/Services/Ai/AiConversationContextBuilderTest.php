<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiCompanyKnowledge;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiConversationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConversationContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_includes_formatted_recent_history_for_same_conversation(): void
    {
        config()->set('ai.history_messages_limit', 20);

        $company = Company::create(['name' => 'Empresa Builder AI']);
        $user = $this->createCompanyUser($company, 'user-builder-ai@test.local');

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        $otherConversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Primeira mensagem',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => null,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'Resposta anterior',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem mais recente',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $otherConversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem de outra conversa',
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, 'Prompt da empresa');

        $this->assertNotEmpty($context);
        $this->assertSame(AiMessage::ROLE_SYSTEM, $context[0]['role']);
        $this->assertStringContainsString('Prompt da empresa', $context[0]['content']);

        $historyPrompt = collect($context)
            ->first(fn (array $item) => str_contains((string) ($item['content'] ?? ''), 'Histórico recente:'));

        $this->assertNotNull($historyPrompt);
        $this->assertStringContainsString('User: Primeira mensagem', $historyPrompt['content']);
        $this->assertStringContainsString('Assistant: Resposta anterior', $historyPrompt['content']);
        $this->assertStringContainsString('User: Mensagem mais recente', $historyPrompt['content']);
        $this->assertStringNotContainsString('Mensagem de outra conversa', $historyPrompt['content']);

        $historyMessages = collect($context)
            ->filter(fn (array $item) => ($item['role'] ?? '') !== AiMessage::ROLE_SYSTEM)
            ->values();

        $this->assertCount(3, $historyMessages);
        $this->assertSame('Primeira mensagem', $historyMessages[0]['content']);
        $this->assertSame('Resposta anterior', $historyMessages[1]['content']);
        $this->assertSame('Mensagem mais recente', $historyMessages[2]['content']);
    }

    public function test_builder_respects_history_limit_between_ten_and_twenty_messages(): void
    {
        config()->set('ai.history_messages_limit', 30);

        $company = Company::create(['name' => 'Empresa Builder Limit']);
        $user = $this->createCompanyUser($company, 'user-builder-limit@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_max_context_messages' => 30,
        ]);

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        for ($index = 1; $index <= 25; $index++) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'user_id' => $index % 2 === 0 ? null : $user->id,
                'role' => $index % 2 === 0 ? AiMessage::ROLE_ASSISTANT : AiMessage::ROLE_USER,
                'content' => "Mensagem {$index}",
            ]);
        }

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, null, null, $settings);

        $historyMessages = collect($context)
            ->filter(fn (array $item) => ($item['role'] ?? '') !== AiMessage::ROLE_SYSTEM)
            ->values();

        $this->assertCount(20, $historyMessages);
        $this->assertSame('Mensagem 6', $historyMessages[0]['content']);
        $this->assertSame('Mensagem 25', $historyMessages[19]['content']);
    }

    public function test_builder_combines_global_prompt_with_company_persona_tone_language_and_formality(): void
    {
        $company = Company::create(['name' => 'Empresa Builder Persona']);
        $user = $this->createCompanyUser($company, 'user-builder-persona@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_system_prompt' => 'Prompt da empresa',
            'ai_persona' => 'Especialista operacional',
            'ai_tone' => 'Objetivo',
            'ai_language' => 'pt-BR',
            'ai_formality' => 'Formal',
        ]);

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, 'Prompt global', null, $settings);

        $this->assertNotEmpty($context);
        $this->assertSame(AiMessage::ROLE_SYSTEM, $context[0]['role']);
        $this->assertStringContainsString('Prompt global', $context[0]['content']);
        $this->assertStringContainsString('Prompt da empresa', $context[0]['content']);
        $this->assertStringContainsString('Persona: Especialista operacional', $context[0]['content']);
        $this->assertStringContainsString('Tom: Objetivo', $context[0]['content']);
        $this->assertStringContainsString('Idioma: pt-BR', $context[0]['content']);
        $this->assertStringContainsString('Formalidade: Formal', $context[0]['content']);
    }

    public function test_builder_includes_up_to_five_active_knowledge_entries_and_available_tools(): void
    {
        $company = Company::create(['name' => 'Empresa Builder Knowledge']);
        $otherCompany = Company::create(['name' => 'Outra Empresa Builder Knowledge']);
        $user = $this->createCompanyUser($company, 'user-builder-knowledge@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_max_context_messages' => 10,
        ]);

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 1',
            'content' => 'Conteúdo 1',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 2',
            'content' => 'Conteúdo 2',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 3',
            'content' => 'Conteúdo 3',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 4',
            'content' => 'Conteúdo 4',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 5',
            'content' => 'Conteúdo 5',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 6',
            'content' => 'Conteúdo 6',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base Inativa',
            'content' => 'Conteúdo inativo',
            'is_active' => false,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $otherCompany->id,
            'title' => 'Base Outra Empresa',
            'content' => 'Conteúdo outra empresa',
            'is_active' => true,
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, 'Prompt global', null, $settings);

        $knowledgePrompt = collect($context)
            ->first(fn (array $item) => str_contains((string) ($item['content'] ?? ''), 'Informacoes da empresa:'));

        $this->assertNotNull($knowledgePrompt);
        $this->assertStringContainsString('- Base 6: Conteúdo 6', $knowledgePrompt['content']);
        $this->assertStringContainsString('- Base 5: Conteúdo 5', $knowledgePrompt['content']);
        $this->assertStringContainsString('- Base 4: Conteúdo 4', $knowledgePrompt['content']);
        $this->assertStringContainsString('- Base 3: Conteúdo 3', $knowledgePrompt['content']);
        $this->assertStringContainsString('- Base 2: Conteúdo 2', $knowledgePrompt['content']);
        $this->assertStringNotContainsString('Base 1', $knowledgePrompt['content']);
        $this->assertStringNotContainsString('Base Inativa', $knowledgePrompt['content']);
        $this->assertStringNotContainsString('Base Outra Empresa', $knowledgePrompt['content']);

        $toolsPrompt = $context[count($context) - 1];
        $this->assertSame(AiMessage::ROLE_SYSTEM, $toolsPrompt['role']);
        $this->assertStringContainsString('Ferramentas disponiveis:', $toolsPrompt['content']);
        $this->assertStringContainsString('get_customer_by_phone', $toolsPrompt['content']);
    }

    private function createCompanyUser(Company $company, string $email): User
    {
        return User::create([
            'name' => 'User Builder AI',
            'email' => $email,
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
            'can_use_ai' => true,
        ]);
    }
}
