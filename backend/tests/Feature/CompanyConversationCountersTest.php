<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyConversationCountersTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_counters_endpoint_returns_totals_grouped_by_area(): void
    {
        $company = Company::create(['name' => 'Empresa Contadores']);
        $agent = User::create([
            'name' => 'Agente Contador',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'secret',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $financeArea = Area::create([
            'company_id' => $company->id,
            'name' => 'Financeiro',
        ]);

        $openFinanceConversation = $this->makeConversation($company, $financeArea->id, ConversationStatus::OPEN);
        $openWithoutAreaConversation = $this->makeConversation($company, null, ConversationStatus::IN_PROGRESS);
        $this->makeConversation($company, $financeArea->id, ConversationStatus::CLOSED);

        Message::create([
            'conversation_id' => $openFinanceConversation->id,
            'direction' => 'in',
            'text' => 'Cliente aguardando retorno.',
        ]);
        Message::create([
            'conversation_id' => $openWithoutAreaConversation->id,
            'direction' => 'out',
            'text' => 'Resposta enviada.',
        ]);

        $response = $this->actingAs($agent)
            ->getJson('/api/minha-conta/conversas/contadores');

        $response->assertOk()
            ->assertJsonPath('total_abertas', 2)
            ->assertJsonPath('sem_area.total_abertas', 1)
            ->assertJsonPath('por_area.0.area_nome', 'Financeiro')
            ->assertJsonPath('por_area.0.total_abertas', 1)
            ->assertJsonPath('por_area.0.total_sem_resposta', 1);
    }

    private function makeConversation(Company $company, ?int $areaId, string $status): Conversation
    {
        return Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511' . random_int(10000000, 99999999),
            'status' => $status,
            'handling_mode' => ConversationHandlingMode::BOT,
            'assigned_type' => ConversationAssignedType::UNASSIGNED,
            'current_area_id' => $areaId,
        ]);
    }
}
