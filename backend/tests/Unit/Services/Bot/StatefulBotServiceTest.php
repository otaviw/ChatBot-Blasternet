<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Bot;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\BotFlowRegistry;
use App\Services\Bot\Handlers\AppointmentFlowHandler;
use App\Services\Bot\Handlers\GeneralMenuFlowHandler;
use App\Services\Bot\Handlers\IxcFiscalNoteFlowHandler;
use App\Services\Bot\Handlers\IxcInvoiceFlowHandler;
use App\Services\Bot\StatefulBotService;
use Mockery;
use Tests\TestCase;

class StatefulBotServiceTest extends TestCase
{
    public function test_natural_language_menu_commands_return_to_initial_menu(): void
    {
        $registry = Mockery::mock(BotFlowRegistry::class);
        $appointmentHandler = Mockery::mock(AppointmentFlowHandler::class);
        $ixcInvoiceHandler = Mockery::mock(IxcInvoiceFlowHandler::class);
        $ixcFiscalNoteHandler = Mockery::mock(IxcFiscalNoteFlowHandler::class);
        $service = $this->makeService($registry, $appointmentHandler, $ixcInvoiceHandler, $ixcFiscalNoteHandler);

        $method = new \ReflectionMethod(StatefulBotService::class, 'isMenuCommand');
        $method->setAccessible(true);

        foreach ([
            'preciso ir par ao menu',
            'quero voltar pro inicio',
            'me leva para o menu',
            'tela inicial',
        ] as $input) {
            $this->assertTrue((bool) $method->invoke($service, $input, ['#', 'menu']), $input);
        }
    }

    public function test_ai_resolved_service_intent_can_switch_from_active_flow_to_initial_menu_option(): void
    {
        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;

        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;
        $conversation->bot_flow = 'appointments';
        $conversation->bot_step = 'choose_datetime';

        $definition = $this->menuDefinition();

        $registry = Mockery::mock(BotFlowRegistry::class);
        $registry->shouldReceive('definitionForCompany')->once()->with($company)->andReturn($definition);

        $appointmentHandler = Mockery::mock(AppointmentFlowHandler::class);
        $ixcInvoiceHandler = Mockery::mock(IxcInvoiceFlowHandler::class);
        $ixcFiscalNoteHandler = Mockery::mock(IxcFiscalNoteFlowHandler::class);

        $ixcInvoiceHandler
            ->shouldReceive('start')
            ->once()
            ->with($company, $conversation, Mockery::on(
                static fn (array $action): bool => ($action['kind'] ?? null) === 'ixc_invoices_start'
            ))
            ->andReturn([
                'handled' => true,
                'reply_text' => 'Informe seu CPF para buscar o boleto.',
                'reply_message' => null,
                'should_handoff' => false,
            ]);

        $service = $this->makeService($registry, $appointmentHandler, $ixcInvoiceHandler, $ixcFiscalNoteHandler);

        $result = $service->handleAiResolvedMenuAction($company, $conversation, 'financeiro', 'errei, quero boleto');

        $this->assertIsArray($result);
        $this->assertTrue((bool) ($result['handled'] ?? false));
        $this->assertSame('Informe seu CPF para buscar o boleto.', $result['reply_text'] ?? null);
    }

    public function test_ai_resolved_attendant_intent_does_not_use_initial_menu_as_shortcut_from_active_flow(): void
    {
        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;

        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;
        $conversation->bot_flow = 'appointments';
        $conversation->bot_step = 'choose_datetime';

        $registry = Mockery::mock(BotFlowRegistry::class);
        $registry->shouldReceive('definitionForCompany')->once()->with($company)->andReturn($this->menuDefinition());

        $appointmentHandler = Mockery::mock(AppointmentFlowHandler::class);
        $ixcInvoiceHandler = Mockery::mock(IxcInvoiceFlowHandler::class);
        $ixcFiscalNoteHandler = Mockery::mock(IxcFiscalNoteFlowHandler::class);

        $appointmentHandler->shouldNotReceive('start');
        $ixcInvoiceHandler->shouldNotReceive('start');
        $ixcFiscalNoteHandler->shouldNotReceive('start');

        $service = $this->makeService($registry, $appointmentHandler, $ixcInvoiceHandler, $ixcFiscalNoteHandler);

        $result = $service->handleAiResolvedMenuAction($company, $conversation, 'falar_com_atendente', 'quero atendente');

        $this->assertNull($result);
    }

    public function test_ai_resolved_specific_invoice_request_can_jump_to_finance_submenu_option(): void
    {
        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;

        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;

        $definition = $this->financeSubmenuDefinition();

        $registry = Mockery::mock(BotFlowRegistry::class);
        $registry->shouldReceive('definitionForCompany')->once()->with($company)->andReturn($definition);

        $appointmentHandler = Mockery::mock(AppointmentFlowHandler::class);
        $ixcInvoiceHandler = Mockery::mock(IxcInvoiceFlowHandler::class);
        $ixcFiscalNoteHandler = Mockery::mock(IxcFiscalNoteFlowHandler::class);

        $ixcInvoiceHandler
            ->shouldReceive('start')
            ->once()
            ->with($company, $conversation, Mockery::on(
                static fn (array $action): bool => ($action['kind'] ?? null) === 'ixc_invoices_start'
            ))
            ->andReturn([
                'handled' => true,
                'reply_text' => 'Informe seu CPF para buscar o boleto.',
                'reply_message' => null,
                'should_handoff' => false,
            ]);

        $service = $this->makeService($registry, $appointmentHandler, $ixcInvoiceHandler, $ixcFiscalNoteHandler);

        $result = $service->handleAiResolvedMenuAction(
            $company,
            $conversation,
            'financeiro',
            'quero o boleto da minha empresa cpf: 149.949.899-31'
        );

        $this->assertIsArray($result);
        $this->assertTrue((bool) ($result['handled'] ?? false));
        $this->assertSame('Informe seu CPF para buscar o boleto.', $result['reply_text'] ?? null);
    }

    private function makeService(
        BotFlowRegistry $registry,
        AppointmentFlowHandler $appointmentHandler,
        IxcInvoiceFlowHandler $ixcInvoiceHandler,
        IxcFiscalNoteFlowHandler $ixcFiscalNoteHandler,
    ): StatefulBotService {
        $generalMenuHandler = new GeneralMenuFlowHandler(
            $registry,
            $appointmentHandler,
            $ixcInvoiceHandler,
            $ixcFiscalNoteHandler,
        );

        return new StatefulBotService(
            $registry,
            $appointmentHandler,
            $generalMenuHandler,
            $ixcInvoiceHandler,
            $ixcFiscalNoteHandler,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function menuDefinition(): array
    {
        return [
            'commands' => ['#', 'menu'],
            'initial' => [
                'flow' => 'main',
                'step' => 'menu',
            ],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Ola! O que voce precisa?\n1 - Agendamento\n2 - Boleto\n9 - Falar com atendente",
                    'invalid_option_text' => null,
                    'options' => [
                        '1' => [
                            'label' => 'Agendamento',
                            'action' => [
                                'kind' => 'appointments_start',
                                'target_area_name' => 'Atendimento',
                                'reply_text' => null,
                            ],
                        ],
                        '2' => [
                            'label' => 'Boleto',
                            'action' => [
                                'kind' => 'ixc_invoices_start',
                                'target_area_name' => 'Atendimento',
                                'reply_text' => null,
                            ],
                        ],
                        '9' => [
                            'label' => 'Falar com atendente',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Atendimento',
                                'reply_text' => 'Certo. Vou te encaminhar.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeSubmenuDefinition(): array
    {
        return [
            'commands' => ['#', 'menu'],
            'initial' => [
                'flow' => 'main',
                'step' => 'menu',
            ],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Menu principal\n1 - Financeiro\n2 - Suporte",
                    'invalid_option_text' => null,
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
                    'invalid_option_text' => null,
                    'options' => [
                        '1' => [
                            'label' => 'Segunda via boleto',
                            'action' => [
                                'kind' => 'ixc_invoices_start',
                                'target_area_name' => 'Financeiro',
                                'reply_text' => null,
                            ],
                        ],
                        '2' => [
                            'label' => 'Falar com atendente',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Financeiro',
                                'reply_text' => 'Vou te encaminhar para Financeiro.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
