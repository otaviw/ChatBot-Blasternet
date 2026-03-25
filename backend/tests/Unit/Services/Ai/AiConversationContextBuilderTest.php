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

    public function test_builder_uses_only_recent_messages_from_same_conversation(): void
    {
        config()->set('ai.history_messages_limit', 2);

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

        $this->assertCount(3, $context);
        $this->assertSame(AiMessage::ROLE_SYSTEM, $context[0]['role']);
        $this->assertSame('Prompt da empresa', $context[0]['content']);
        $this->assertSame(AiMessage::ROLE_ASSISTANT, $context[1]['role']);
        $this->assertSame('Resposta anterior', $context[1]['content']);
        $this->assertSame(AiMessage::ROLE_USER, $context[2]['role']);
        $this->assertSame('Mensagem mais recente', $context[2]['content']);
    }

    public function test_builder_respects_company_context_messages_limit(): void
    {
        config()->set('ai.history_messages_limit', 20);

        $company = Company::create(['name' => 'Empresa Builder Limit']);
        $user = $this->createCompanyUser($company, 'user-builder-limit@test.local');

        $settings = CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_max_context_messages' => 2,
        ]);

        $conversation = AiConversation::create([
            'company_id' => $company->id,
            'opened_by_user_id' => $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem 1',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => null,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'Mensagem 2',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => 'Mensagem 3',
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, null, null, $settings);

        $this->assertCount(2, $context);
        $this->assertSame(AiMessage::ROLE_ASSISTANT, $context[0]['role']);
        $this->assertSame('Mensagem 2', $context[0]['content']);
        $this->assertSame(AiMessage::ROLE_USER, $context[1]['role']);
        $this->assertSame('Mensagem 3', $context[1]['content']);
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

    public function test_builder_includes_up_to_three_active_knowledge_entries_for_same_company(): void
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
            'content' => 'Conteudo 1',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 2',
            'content' => 'Conteudo 2',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 3',
            'content' => 'Conteudo 3',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base 4',
            'content' => 'Conteudo 4',
            'is_active' => true,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $company->id,
            'title' => 'Base Inativa',
            'content' => 'Conteudo inativo',
            'is_active' => false,
        ]);
        AiCompanyKnowledge::create([
            'company_id' => $otherCompany->id,
            'title' => 'Base Outra Empresa',
            'content' => 'Conteudo outra empresa',
            'is_active' => true,
        ]);

        $builder = $this->app->make(AiConversationContextBuilder::class);
        $context = $builder->build($conversation, 'Prompt global', null, $settings);

        $this->assertGreaterThanOrEqual(2, count($context));
        $this->assertSame(AiMessage::ROLE_SYSTEM, $context[1]['role']);
        $this->assertStringContainsString('Base de conhecimento da empresa:', $context[1]['content']);
        $this->assertStringContainsString('Base 4', $context[1]['content']);
        $this->assertStringContainsString('Base 3', $context[1]['content']);
        $this->assertStringContainsString('Base 2', $context[1]['content']);
        $this->assertStringNotContainsString('Base 1', $context[1]['content']);
        $this->assertStringNotContainsString('Base Inativa', $context[1]['content']);
        $this->assertStringNotContainsString('Base Outra Empresa', $context[1]['content']);
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

