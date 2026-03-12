<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyInboxVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_owned_or_unassigned_conversations_from_own_areas(): void
    {
        $scenario = $this->seedVisibilityScenario();

        $response = $this->actingAs($scenario['agent'])->getJson('/api/minha-conta/conversas?per_page=50');

        $response->assertOk();
        $response->assertJsonPath('conversations_pagination.total', 2);

        $conversationIds = collect($response->json('conversations'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            (int) $scenario['owned']->id,
            (int) $scenario['supportQueue']->id,
        ], $conversationIds);
    }

    public function test_company_admin_sees_all_conversations_from_company_inbox(): void
    {
        $scenario = $this->seedVisibilityScenario();

        $response = $this->actingAs($scenario['companyAdmin'])->getJson('/api/minha-conta/conversas?per_page=50');

        $response->assertOk();
        $response->assertJsonPath('conversations_pagination.total', 5);

        $conversationIds = collect($response->json('conversations'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            (int) $scenario['owned']->id,
            (int) $scenario['supportQueue']->id,
            (int) $scenario['supportAssignedOther']->id,
            (int) $scenario['salesQueue']->id,
            (int) $scenario['botNoArea']->id,
        ], $conversationIds);
    }

    public function test_agent_cannot_open_conversation_outside_inbox_visibility_scope(): void
    {
        $scenario = $this->seedVisibilityScenario();
        $agent = $scenario['agent'];

        $this->actingAs($agent)
            ->getJson("/api/minha-conta/conversas/{$scenario['owned']->id}")
            ->assertOk();

        $this->actingAs($agent)
            ->getJson("/api/minha-conta/conversas/{$scenario['supportQueue']->id}")
            ->assertOk();

        $this->actingAs($agent)
            ->getJson("/api/minha-conta/conversas/{$scenario['supportAssignedOther']->id}")
            ->assertStatus(404);

        $this->actingAs($agent)
            ->getJson("/api/minha-conta/conversas/{$scenario['salesQueue']->id}")
            ->assertStatus(404);

        $this->actingAs($scenario['companyAdmin'])
            ->getJson("/api/minha-conta/conversas/{$scenario['salesQueue']->id}")
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedVisibilityScenario(): array
    {
        $company = Company::create(['name' => 'Empresa Inbox']);
        $supportArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Suporte',
        ]);
        $salesArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Vendas',
        ]);

        $companyAdmin = User::create([
            'name' => 'Admin Empresa',
            'email' => 'admin-inbox@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $agent = User::create([
            'name' => 'Agente Suporte',
            'email' => 'agent-inbox@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $otherAgent = User::create([
            'name' => 'Outro Agente',
            'email' => 'agent-other-inbox@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $agent->areas()->attach($supportArea->id);
        $otherAgent->areas()->attach($supportArea->id);

        $owned = $this->makeConversation($company, [
            'assigned_type' => 'user',
            'assigned_id' => $agent->id,
            'current_area_id' => $supportArea->id,
            'handling_mode' => 'human',
            'assigned_user_id' => $agent->id,
            'assigned_area' => $supportArea->name,
            'status' => 'in_progress',
        ]);
        $supportQueue = $this->makeConversation($company, [
            'assigned_type' => 'area',
            'assigned_id' => $supportArea->id,
            'current_area_id' => $supportArea->id,
            'handling_mode' => 'human',
            'assigned_user_id' => null,
            'assigned_area' => $supportArea->name,
            'status' => 'in_progress',
        ]);
        $supportAssignedOther = $this->makeConversation($company, [
            'assigned_type' => 'user',
            'assigned_id' => $otherAgent->id,
            'current_area_id' => $supportArea->id,
            'handling_mode' => 'human',
            'assigned_user_id' => $otherAgent->id,
            'assigned_area' => $supportArea->name,
            'status' => 'in_progress',
        ]);
        $salesQueue = $this->makeConversation($company, [
            'assigned_type' => 'area',
            'assigned_id' => $salesArea->id,
            'current_area_id' => $salesArea->id,
            'handling_mode' => 'human',
            'assigned_user_id' => null,
            'assigned_area' => $salesArea->name,
            'status' => 'in_progress',
        ]);
        $botNoArea = $this->makeConversation($company, [
            'assigned_type' => 'bot',
            'assigned_id' => null,
            'current_area_id' => null,
            'handling_mode' => 'bot',
            'assigned_user_id' => null,
            'assigned_area' => null,
            'status' => 'open',
        ]);

        return [
            'companyAdmin' => $companyAdmin,
            'agent' => $agent,
            'otherAgent' => $otherAgent,
            'supportArea' => $supportArea,
            'salesArea' => $salesArea,
            'owned' => $owned,
            'supportQueue' => $supportQueue,
            'supportAssignedOther' => $supportAssignedOther,
            'salesQueue' => $salesQueue,
            'botNoArea' => $botNoArea,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConversation(Company $company, array $overrides = []): Conversation
    {
        $defaults = [
            'company_id' => $company->id,
            'customer_phone' => $this->nextPhone(),
            'customer_name' => null,
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'assigned_id' => null,
            'current_area_id' => null,
            'handling_mode' => 'bot',
            'assigned_user_id' => null,
            'assigned_area' => null,
            'assumed_at' => null,
            'closed_at' => null,
        ];

        return Conversation::create(array_merge($defaults, $overrides));
    }

    private function nextPhone(): string
    {
        static $phone = 5511999900000;
        $phone++;

        return (string) $phone;
    }
}

