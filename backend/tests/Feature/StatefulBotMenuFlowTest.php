<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatefulBotMenuFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensão pdo_sqlite não habilitada neste ambiente.');
        }

        parent::setUp();
    }

    public function test_menu_command_resets_state_and_replies_main_menu(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Menu');
        $user = $this->createCompanyUser($company, 'menu@test.local');

        $response = $this->simulateInbound($user, $company, '5511911110001', '  menu  ');
        $response->assertOk();
        $response->assertJsonPath('auto_replied', true);
        $response->assertJsonPath('reply', $this->mainMenuText('Oi. Como posso ajudar?'));

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $this->assertSame('main', $conversation->bot_flow);
        $this->assertSame('menu', $conversation->bot_step);
        $this->assertSame(['1', '2', '3'], $conversation->bot_context['last_menu_keys'] ?? []);
    }

    public function test_option_one_from_main_menu_goes_to_support_submenu(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Suporte');
        $user = $this->createCompanyUser($company, 'submenu@test.local');

        $this->simulateInbound($user, $company, '5511911110002', '#')->assertOk();
        $response = $this->simulateInbound($user, $company, '5511911110002', '1');

        $response->assertOk();
        $response->assertJsonPath('reply', $this->supportMenuText());

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $this->assertSame('support', $conversation->bot_flow);
        $this->assertSame('issue_menu', $conversation->bot_step);
        $this->assertSame('bot', $conversation->handling_mode);
    }

    public function test_invalid_option_keeps_state_and_replies_invalid_message(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Invalida');
        $user = $this->createCompanyUser($company, 'invalid@test.local');

        $this->simulateInbound($user, $company, '5511911110003', '#')->assertOk();
        $response = $this->simulateInbound($user, $company, '5511911110003', '9');

        $response->assertOk();
        $response->assertJsonPath('reply', 'Opção inválida. Responda com 1, 2, 3.');

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $this->assertSame('main', $conversation->bot_flow);
        $this->assertSame('menu', $conversation->bot_step);
    }

    public function test_option_two_handoffs_to_sales_and_stops_auto_reply_after_manual_mode(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Vendas');
        Area::create([
            'company_id' => $company->id,
            'name' => 'Vendas',
        ]);
        $user = $this->createCompanyUser($company, 'vendas@test.local');

        $this->simulateInbound($user, $company, '5511911110004', '#')->assertOk();
        $handoff = $this->simulateInbound($user, $company, '5511911110004', '2');

        $handoff->assertOk();
        $handoff->assertJsonPath('reply', 'Perfeito. Vou te encaminhar para Vendas.');

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $targetArea = Area::query()
            ->where('company_id', $company->id)
            ->where('name', 'Vendas')
            ->firstOrFail();

        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('area', $conversation->assigned_type);
        $this->assertSame((int) $targetArea->id, (int) $conversation->assigned_id);
        $this->assertSame((int) $targetArea->id, (int) $conversation->current_area_id);
        $this->assertSame('Vendas', $conversation->assigned_area);
        $this->assertNull($conversation->bot_flow);
        $this->assertNull($conversation->bot_step);

        $afterHandoff = $this->simulateInbound($user, $company, '5511911110004', 'Tem alguem ai?');
        $afterHandoff->assertOk();
        $afterHandoff->assertJsonPath('auto_replied', false);
        $afterHandoff->assertJsonPath('out_message.id', null);
    }

    public function test_option_three_handoffs_to_attendance_and_stops_auto_reply_after_manual_mode(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Atendimento');
        Area::create([
            'company_id' => $company->id,
            'name' => 'Atendimento',
        ]);
        $user = $this->createCompanyUser($company, 'atendimento@test.local');

        $this->simulateInbound($user, $company, '5511911110005', '#')->assertOk();
        $handoff = $this->simulateInbound($user, $company, '5511911110005', '3');

        $handoff->assertOk();
        $handoff->assertJsonPath('reply', 'Certo. Vou te encaminhar para um atendente.');

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $targetArea = Area::query()
            ->where('company_id', $company->id)
            ->where('name', 'Atendimento')
            ->firstOrFail();

        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('area', $conversation->assigned_type);
        $this->assertSame((int) $targetArea->id, (int) $conversation->assigned_id);
        $this->assertSame((int) $targetArea->id, (int) $conversation->current_area_id);
        $this->assertSame('Atendimento', $conversation->assigned_area);
        $this->assertNull($conversation->bot_flow);
        $this->assertNull($conversation->bot_step);

        $afterHandoff = $this->simulateInbound($user, $company, '5511911110005', 'Tem alguem ai?');
        $afterHandoff->assertOk();
        $afterHandoff->assertJsonPath('auto_replied', false);
        $afterHandoff->assertJsonPath('out_message.id', null);
    }

    public function test_first_message_enters_menu_flow_without_hash_command(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Fallback', [
            'welcome_message' => 'WELCOME_LEGACY',
            'fallback_message' => 'FALLBACK_LEGACY',
        ]);
        $user = $this->createCompanyUser($company, 'legacy@test.local');

        $first = $this->simulateInbound($user, $company, '5511911110006', 'mensagem qualquer');
        $first->assertOk();
        $first->assertJsonPath('reply', "WELCOME_LEGACY\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente");

        $conversation = Conversation::findOrFail((int) $first->json('conversation.id'));
        $this->assertSame('main', $conversation->bot_flow);
        $this->assertSame('menu', $conversation->bot_step);

        $second = $this->simulateInbound($user, $company, '5511911110006', 'segunda mensagem');
        $second->assertOk();
        $second->assertJsonPath('reply', 'Opção inválida. Responda com 1, 2, 3.');
    }

    public function test_company_can_configure_multiple_stateful_menus_from_settings(): void
    {
        $statefulMenuFlow = [
            'commands' => ['#', 'menu', 'inicio'],
            'initial' => ['flow' => 'main', 'step' => 'menu'],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Menu principal\n1 - Financeiro\n2 - Suporte",
                    'options' => [
                        '1' => [
                            'label' => 'Financeiro',
                            'action' => [
                                'kind' => 'go_to',
                                'flow' => 'finance',
                                'step' => 'menu',
                            ],
                        ],
                        '2' => [
                            'label' => 'Suporte',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Suporte',
                                'reply_text' => 'Encaminhando para Suporte.',
                            ],
                        ],
                    ],
                ],
                'finance.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Financeiro\n1 - Segunda via boleto\n2 - Falar com atendente",
                    'options' => [
                        '1' => [
                            'label' => 'Segunda via boleto',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Financeiro',
                                'reply_text' => 'Vou te encaminhar para o Financeiro.',
                            ],
                        ],
                        '2' => [
                            'label' => 'Atendente',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Atendimento',
                                'reply_text' => 'Vou te encaminhar para Atendimento.',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Multi Menu', [
            'stateful_menu_flow' => $statefulMenuFlow,
        ]);
        Area::create(['company_id' => $company->id, 'name' => 'Suporte']);
        Area::create(['company_id' => $company->id, 'name' => 'Financeiro']);
        Area::create(['company_id' => $company->id, 'name' => 'Atendimento']);
        $user = $this->createCompanyUser($company, 'multi-menu@test.local');

        $start = $this->simulateInbound($user, $company, '5511911110007', 'inicio');
        $start->assertOk();
        $start->assertJsonPath('reply', "Menu principal\n1 - Financeiro\n2 - Suporte");

        $toFinanceMenu = $this->simulateInbound($user, $company, '5511911110007', '1');
        $toFinanceMenu->assertOk();
        $toFinanceMenu->assertJsonPath('reply', "Financeiro\n1 - Segunda via boleto\n2 - Falar com atendente");

        $conversation = Conversation::findOrFail((int) $toFinanceMenu->json('conversation.id'));
        $this->assertSame('finance', $conversation->bot_flow);
        $this->assertSame('menu', $conversation->bot_step);

        $handoff = $this->simulateInbound($user, $company, '5511911110007', '1');
        $handoff->assertOk();
        $handoff->assertJsonPath('reply', 'Vou te encaminhar para o Financeiro.');

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $financeArea = Area::query()
            ->where('company_id', $company->id)
            ->where('name', 'Financeiro')
            ->firstOrFail();

        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('area', $conversation->assigned_type);
        $this->assertSame((int) $financeArea->id, (int) $conversation->assigned_id);
        $this->assertSame((int) $financeArea->id, (int) $conversation->current_area_id);
        $this->assertSame('Financeiro', $conversation->assigned_area);

        $afterHandoff = $this->simulateInbound($user, $company, '5511911110007', 'oi');
        $afterHandoff->assertOk();
        $afterHandoff->assertJsonPath('auto_replied', false);
        $afterHandoff->assertJsonPath('out_message.id', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompanyWithAlwaysOnBot(string $name, array $overrides = []): Company
    {
        $company = Company::create(['name' => $name]);

        $base = [
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi. Como posso ajudar?',
            'fallback_message' => 'Não entendi sua mensagem. Pode reformular?',
            'out_of_hours_message' => 'Fora do horário',
            'business_hours' => [
                'monday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'tuesday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'wednesday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'thursday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'friday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'saturday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
                'sunday' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59'],
            ],
            'keyword_replies' => [],
        ];

        CompanyBotSetting::create(array_replace_recursive($base, $overrides));

        return $company;
    }

    private function createCompanyUser(Company $company, string $email): User
    {
        return User::create([
            'name' => 'Operador',
            'email' => $email,
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    private function mainMenuText(string $welcomeMessage): string
    {
        return "{$welcomeMessage}\n1 - Suporte técnico\n2 - Vendas\n3 - Falar com atendente";
    }

    private function supportMenuText(): string
    {
        return "Suporte técnico. Qual o problema?\n1 - Internet lenta\n2 - Sem conexão\n3 - Outro";
    }

    private function simulateInbound(User $user, Company $company, string $from, string $text)
    {
        return $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => $from,
            'text' => $text,
            'send_outbound' => false,
        ]);
    }
}
