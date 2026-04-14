<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_search_conversations_by_phone(): void
    {
        $company = Company::create(['name' => 'Empresa Busca']);
        $otherCompany = Company::create(['name' => 'Empresa Outra']);
        $agent = $this->makeAgent($company);

        $matchingConversation = $this->makeConversation($company, '5511999987654');
        Message::create([
            'conversation_id' => $matchingConversation->id,
            'direction' => 'in',
            'text' => 'Primeira mensagem para contexto.',
        ]);

        $otherCompanyConversation = $this->makeConversation($otherCompany, '5511999987654');
        Message::create([
            'conversation_id' => $otherCompanyConversation->id,
            'direction' => 'in',
            'text' => 'Nao deve aparecer.',
        ]);

        $response = $this->actingAs($agent)
            ->getJson('/api/minha-conta/conversas/buscar?q=9987654');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.id', $matchingConversation->id)
            ->assertJsonPath('results.0.customer_phone', '5511999987654');
    }

    public function test_company_user_can_search_conversations_by_message_text(): void
    {
        $company = Company::create(['name' => 'Empresa Busca Texto']);
        $agent = $this->makeAgent($company);

        $matchingConversation = $this->makeConversation($company, '5511988881111');
        Message::create([
            'conversation_id' => $matchingConversation->id,
            'direction' => 'in',
            'text' => 'Cliente informou que o boleto venceu ontem e precisa de segunda via.',
        ]);

        $otherConversation = $this->makeConversation($company, '5511977772222');
        Message::create([
            'conversation_id' => $otherConversation->id,
            'direction' => 'in',
            'text' => 'Mensagem sem o termo pesquisado.',
        ]);

        $response = $this->actingAs($agent)
            ->getJson('/api/minha-conta/conversas/buscar?q=boleto');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.id', $matchingConversation->id);

        $snippet = (string) $response->json('results.0.snippet');
        $this->assertStringContainsStringIgnoringCase('boleto', $snippet);
    }

    public function test_company_user_can_search_conversations_by_action(): void
    {
        $company = Company::create(['name' => 'Empresa Busca Acao']);
        $agent = $this->makeAgent($company);

        $matchingConversation = $this->makeConversation($company, '5511911111111');
        $otherConversation = $this->makeConversation($company, '5511922222222');

        AuditLog::create([
            'company_id' => $company->id,
            'actor_role' => User::ROLE_AGENT,
            'actor_company_id' => $company->id,
            'action' => 'company.conversation.transferred',
            'changes' => ['conversation_id' => $matchingConversation->id],
            'meta' => ['conversation_id' => $matchingConversation->id],
            'created_at' => now(),
        ]);

        AuditLog::create([
            'company_id' => $company->id,
            'actor_role' => User::ROLE_AGENT,
            'actor_company_id' => $company->id,
            'action' => 'company.conversation.contact_updated',
            'changes' => ['conversation_id' => $otherConversation->id],
            'meta' => ['conversation_id' => $otherConversation->id],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($agent)
            ->getJson('/api/minha-conta/conversas/buscar?q=transferida');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.id', $matchingConversation->id);

        $snippet = (string) $response->json('results.0.snippet');
        $this->assertStringContainsStringIgnoringCase('ação', $snippet);
    }

    public function test_company_user_can_search_messages_inside_selected_conversation(): void
    {
        $company = Company::create(['name' => 'Empresa Busca Interna']);
        $agent = $this->makeAgent($company);
        $conversation = $this->makeConversation($company, '5511933333333');

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'text' => 'Cliente pediu reembolso parcial na assinatura.',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'text' => 'Confirmamos que o reembolso parcial sera analisado.',
        ]);

        $response = $this->actingAs($agent)
            ->getJson("/api/minha-conta/conversas/{$conversation->id}/mensagens/buscar?q=reembolso");

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('results.0.message_id', Message::query()->latest('id')->value('id'));
    }

    private function makeAgent(Company $company): User
    {
        return User::create([
            'name' => 'Agente Busca',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'secret',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    private function makeConversation(Company $company, string $phone): Conversation
    {
        return Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => $phone,
            'status' => ConversationStatus::OPEN,
            'handling_mode' => ConversationHandlingMode::BOT,
            'assigned_type' => ConversationAssignedType::UNASSIGNED,
        ]);
    }
}
