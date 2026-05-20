<?php

namespace Tests\Feature;

use App\Models\AiChatbotDecisionLog;
use App\Models\AiUsageLog;
use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\ChatbotAiPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite nao habilitada neste ambiente.');
        }

        parent::setUp();
    }

    private function makeCompanyAdmin(string $companyName = 'Analytics Co'): array
    {
        $company = Company::create(['name' => $companyName]);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_chatbot_enabled' => true,
        ]);

        $user = User::create([
            'name' => "{$companyName} Admin",
            'email' => mb_strtolower(str_replace(' ', '-', $companyName)).'@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    private function seedUsage(int $companyId, array $overrides = [], int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiUsageLog::create(array_merge([
                'company_id' => $companyId,
                'user_id' => null,
                'conversation_id' => null,
                'type' => AiUsageLog::TYPE_CHATBOT,
                'provider' => 'test',
                'model' => 'test-model',
                'feature' => AiUsageLog::FEATURE_CHATBOT,
                'status' => AiUsageLog::STATUS_OK,
                'message_length' => 80,
                'tokens_used' => 100,
                'response_time_ms' => 500,
                'error_type' => null,
                'created_at' => now(),
            ], $overrides));
        }
    }

    private function seedDecision(int $companyId, array $overrides = []): AiChatbotDecisionLog
    {
        return AiChatbotDecisionLog::create(array_merge([
            'company_id' => $companyId,
            'conversation_id' => null,
            'message_id' => null,
            'user_id' => null,
            'channel' => AiChatbotDecisionLog::CHANNEL_WHATSAPP,
            'flow' => 'main',
            'step' => 'menu',
            'mode' => AiChatbotDecisionLog::MODE_ACTIVE,
            'gate_result' => ['allowed' => true],
            'intent' => 'agendamento',
            'confidence' => 0.9,
            'action' => ChatbotAiPolicyService::ACTION_ROUTE_TO_APPOINTMENT_FLOW,
            'handoff_reason' => null,
            'handoff_area_id' => null,
            'handoff_area_name' => null,
            'handoff_type' => null,
            'used_knowledge' => false,
            'knowledge_refs' => null,
            'latency_ms' => 120,
            'tokens_used' => 40,
            'provider' => 'test',
            'model' => 'test-model',
            'error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_dashboard_metrics_are_scoped_by_company_and_period(): void
    {
        [$companyA, $userA] = $this->makeCompanyAdmin('Empresa A');
        [$companyB] = $this->makeCompanyAdmin('Empresa B');

        $this->seedUsage($companyA->id, ['tokens_used' => 150], 2);
        $this->seedUsage($companyB->id, ['tokens_used' => 999], 5);
        $this->seedDecision($companyA->id);
        $this->seedDecision($companyB->id, ['intent' => 'financeiro']);
        $this->seedUsage($companyA->id, [
            'created_at' => now()->subDays(40),
            'tokens_used' => 800,
        ]);

        $response = $this->actingAs($userA)->getJson(
            '/api/minha-conta/ia/analytics?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString()
        );

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonPath('summary.provider_requests', 2);
        $response->assertJsonPath('summary.chatbot_decisions', 1);
        $response->assertJsonPath('summary.total_tokens', 300);
        $response->assertJsonPath('selected_company_id', $companyA->id);

        $intents = collect($response->json('top_intents'));
        $this->assertNotNull($intents->firstWhere('intent', 'agendamento'));
        $this->assertNull($intents->firstWhere('intent', 'financeiro'));
    }

    public function test_dashboard_differentiates_menu_and_incapacity_handoffs(): void
    {
        [$company, $user] = $this->makeCompanyAdmin();
        $area = Area::create(['company_id' => $company->id, 'name' => 'Suporte']);

        $this->seedDecision($company->id, [
            'intent' => 'falar_com_atendente',
            'action' => ChatbotAiPolicyService::ACTION_HANDOFF,
            'handoff_type' => AiChatbotDecisionLog::HANDOFF_TYPE_MENU,
            'handoff_area_id' => $area->id,
            'handoff_area_name' => 'Suporte',
            'handoff_reason' => null,
            'flow' => 'main',
        ]);
        $this->seedDecision($company->id, [
            'intent' => 'financeiro',
            'action' => ChatbotAiPolicyService::ACTION_HANDOFF,
            'handoff_type' => AiChatbotDecisionLog::HANDOFF_TYPE_INCAPACITY,
            'handoff_area_id' => $area->id,
            'handoff_area_name' => 'Suporte',
            'handoff_reason' => 'outside_company_scope',
            'flow' => 'ixc_invoice',
        ]);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/analytics?channel=whatsapp');

        $response->assertOk();
        $response->assertJsonPath('summary.handoff_count', 2);
        $response->assertJsonPath('summary.handoff_menu_count', 1);
        $response->assertJsonPath('summary.handoff_incapacity_count', 1);

        $handoffs = collect($response->json('handoff_by_type'))->pluck('count', 'type');
        $this->assertSame(1, $handoffs->get('menu'));
        $this->assertSame(1, $handoffs->get('incapacity'));
    }

    public function test_dashboard_filters_by_area_and_flow(): void
    {
        [$company, $user] = $this->makeCompanyAdmin();
        $support = Area::create(['company_id' => $company->id, 'name' => 'Suporte']);
        $billing = Area::create(['company_id' => $company->id, 'name' => 'Financeiro']);

        $this->seedDecision($company->id, [
            'intent' => 'suporte_tecnico',
            'flow' => 'support_flow',
            'handoff_type' => AiChatbotDecisionLog::HANDOFF_TYPE_INCAPACITY,
            'handoff_area_id' => $support->id,
            'handoff_area_name' => 'Suporte',
            'action' => ChatbotAiPolicyService::ACTION_HANDOFF,
        ]);
        $this->seedDecision($company->id, [
            'intent' => 'financeiro',
            'flow' => 'billing_flow',
            'handoff_type' => AiChatbotDecisionLog::HANDOFF_TYPE_INCAPACITY,
            'handoff_area_id' => $billing->id,
            'handoff_area_name' => 'Financeiro',
            'action' => ChatbotAiPolicyService::ACTION_HANDOFF,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/minha-conta/ia/analytics?area_id={$support->id}&flow=support_flow"
        );

        $response->assertOk();
        $response->assertJsonPath('summary.chatbot_decisions', 1);
        $response->assertJsonPath('filters.area_id', $support->id);
        $response->assertJsonPath('filters.flow', 'support_flow');

        $intents = collect($response->json('top_intents'));
        $this->assertNotNull($intents->firstWhere('intent', 'suporte_tecnico'));
        $this->assertNull($intents->firstWhere('intent', 'financeiro'));
    }

    public function test_dashboard_csv_export_is_available(): void
    {
        [$company, $user] = $this->makeCompanyAdmin();
        $this->seedUsage($company->id, ['tokens_used' => 321]);
        $this->seedDecision($company->id);

        $response = $this->actingAs($user)->get('/api/minha-conta/ia/analytics?export=csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('provider_requests', $response->getContent());
        $this->assertStringContainsString('intent,total,handoffs,avg_confidence', $response->getContent() ?: '');
    }
}
