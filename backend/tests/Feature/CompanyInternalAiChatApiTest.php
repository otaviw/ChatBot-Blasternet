<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyInternalAiChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_create_list_show_and_send_message_in_internal_ai_conversation(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');
        config()->set('ai.providers.test.reply_prefix', '[AI-TEST]');

        $company = Company::create(['name' => 'Empresa API IA']);
        $user = $this->createCompanyUser($company, 'api-ia-user@test.local');

        $this->upsertAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
            'ai_model' => 'company-model',
        ]);

        $create = $this->actingAs($user)->postJson('/api/minha-conta/ia/conversas', [
            'title' => 'Conversa Operacional',
        ]);

        $create->assertCreated();
        $create->assertJsonPath('ok', true);
        $conversationId = (int) $create->json('conversation.id');
        $this->assertGreaterThan(0, $conversationId);

        $list = $this->actingAs($user)->getJson('/api/minha-conta/ia/conversas');
        $list->assertOk();
        $this->assertTrue(
            collect($list->json('conversations', []))
                ->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $conversationId)
        );

        $showBefore = $this->actingAs($user)->getJson("/api/minha-conta/ia/conversas/{$conversationId}");
        $showBefore->assertOk();
        $showBefore->assertJsonPath('conversation.id', $conversationId);
        $showBefore->assertJsonPath('messages_pagination.total', 0);

        $send = $this->actingAs($user)->postJson("/api/minha-conta/ia/conversas/{$conversationId}/mensagens", [
            'content' => 'Preciso de um resumo da conversa.',
        ]);

        $send->assertOk();
        $send->assertJsonPath('ok', true);
        $send->assertJsonPath('conversation.id', $conversationId);
        $send->assertJsonPath('user_message.role', 'user');
        $send->assertJsonPath('assistant_message.role', 'assistant');
        $this->assertStringContainsString(
            'Preciso de um resumo da conversa.',
            (string) $send->json('assistant_message.content')
        );

        $showAfter = $this->actingAs($user)->getJson("/api/minha-conta/ia/conversas/{$conversationId}");
        $showAfter->assertOk();
        $showAfter->assertJsonPath('messages_pagination.total', 2);
        $showAfter->assertJsonPath('conversation.messages.0.role', 'user');
        $showAfter->assertJsonPath('conversation.messages.1.role', 'assistant');
    }

    public function test_company_user_cannot_access_internal_ai_conversation_from_another_user_in_same_company(): void
    {
        config()->set('ai.provider', 'test');
        config()->set('ai.model', 'test-model');

        $company = Company::create(['name' => 'Empresa API IA Escopo User']);
        $owner = $this->createCompanyUser($company, 'api-ia-owner@test.local');
        $other = $this->createCompanyUser($company, 'api-ia-other@test.local');

        $this->upsertAiSettings($company, [
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

        $show = $this->actingAs($other)->getJson("/api/minha-conta/ia/conversas/{$conversation->id}");
        $show->assertStatus(404);

        $send = $this->actingAs($other)->postJson("/api/minha-conta/ia/conversas/{$conversation->id}/mensagens", [
            'content' => 'Tentativa de acesso indevido.',
        ]);
        $send->assertStatus(404);
    }

    public function test_company_user_cannot_access_internal_ai_conversation_from_another_company(): void
    {
        config()->set('ai.provider', 'test');

        $companyA = Company::create(['name' => 'Empresa API IA A']);
        $companyB = Company::create(['name' => 'Empresa API IA B']);

        $userA = $this->createCompanyUser($companyA, 'api-ia-a@test.local');
        $userB = $this->createCompanyUser($companyB, 'api-ia-b@test.local');

        $this->upsertAiSettings($companyA, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);
        $this->upsertAiSettings($companyB, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_provider' => 'test',
        ]);

        $conversation = AiConversation::query()->create([
            'company_id' => (int) $companyA->id,
            'opened_by_user_id' => (int) $userA->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
            'title' => 'Thread empresa A',
        ]);

        $show = $this->actingAs($userB)->getJson("/api/minha-conta/ia/conversas/{$conversation->id}");
        $show->assertStatus(404);

        $send = $this->actingAs($userB)->postJson("/api/minha-conta/ia/conversas/{$conversation->id}/mensagens", [
            'content' => 'Tentativa cross-company.',
        ]);
        $send->assertStatus(404);
    }

    public function test_create_internal_ai_conversation_fails_when_ai_is_disabled_for_company(): void
    {
        $company = Company::create(['name' => 'Empresa API IA Disabled']);
        $user = $this->createCompanyUser($company, 'api-ia-disabled@test.local');

        $this->upsertAiSettings($company, [
            'ai_enabled' => false,
            'ai_internal_chat_enabled' => true,
        ]);

        $create = $this->actingAs($user)->postJson('/api/minha-conta/ia/conversas', [
            'title' => 'Nao deveria criar',
        ]);

        $create->assertStatus(422);
        $create->assertJsonPath('message', 'IA desabilitada para esta empresa.');
    }

    public function test_send_message_fails_when_internal_ai_chat_is_disabled_for_company(): void
    {
        $company = Company::create(['name' => 'Empresa API IA Internal Disabled']);
        $user = $this->createCompanyUser($company, 'api-ia-internal-disabled@test.local');

        $this->upsertAiSettings($company, [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => false,
            'ai_provider' => 'test',
        ]);

        $conversation = AiConversation::query()->create([
            'company_id' => (int) $company->id,
            'opened_by_user_id' => (int) $user->id,
            'origin' => AiConversation::ORIGIN_INTERNAL_CHAT,
            'title' => 'Thread bloqueada',
        ]);

        $send = $this->actingAs($user)->postJson("/api/minha-conta/ia/conversas/{$conversation->id}/mensagens", [
            'content' => 'Mensagem bloqueada',
        ]);

        $send->assertStatus(422);
        $send->assertJsonPath('message', 'Chat interno com IA desabilitado para esta empresa.');
    }

    private function createCompanyUser(Company $company, string $email): User
    {
        return User::create([
            'name' => 'User API IA',
            'email' => $email,
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upsertAiSettings(Company $company, array $overrides = []): void
    {
        CompanyBotSetting::updateOrCreate(
            ['company_id' => (int) $company->id],
            array_merge([
                'is_active' => true,
                'timezone' => 'America/Sao_Paulo',
                'business_hours' => [],
                'keyword_replies' => [],
                'service_areas' => [],
                'ai_enabled' => false,
                'ai_internal_chat_enabled' => false,
                'ai_chatbot_auto_reply_enabled' => false,
                'ai_provider' => null,
                'ai_model' => null,
                'ai_system_prompt' => null,
                'ai_temperature' => null,
                'ai_max_response_tokens' => null,
            ], $overrides)
        );
    }
}
