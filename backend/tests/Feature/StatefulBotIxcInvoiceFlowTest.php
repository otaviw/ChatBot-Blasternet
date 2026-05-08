<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\IxcApiService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StatefulBotIxcInvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensão pdo_sqlite não habilitada neste ambiente.');
        }

        parent::setUp();
    }

    public function test_ixc_invoice_flow_sends_document_and_registers_bot_message_status(): void
    {
        $company = $this->createCompanyWithIxcAndInvoiceFlow('Empresa IXC Bot');
        $admin = $this->createSystemAdmin('sys.ixc.bot@test.local');

        $this->mockIxcSuccessPath();
        $this->mockWhatsAppForFlow();

        $this->simulateInbound($admin, $company, '5511999990001', '#')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990001', '1')->assertOk();
        $confirm = $this->simulateInbound($admin, $company, '5511999990001', '12345678901');
        $confirm->assertOk();
        $this->assertStringContainsString('Deseja que eu envie esse boleto agora?', (string) $confirm->json('reply'));

        $final = $this->simulateInbound($admin, $company, '5511999990001', '1');

        $final->assertOk();
        $this->assertStringContainsString('Pronto! Enviei o boleto #77', (string) $final->json('reply'));

        $conversation = Conversation::findOrFail((int) $final->json('conversation.id'));

        $documentMessage = Message::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('direction', 'out')
            ->where('type', 'bot')
            ->where('content_type', 'document')
            ->latest('id')
            ->first();

        $this->assertNotNull($documentMessage);
        $this->assertSame('Boleto 77', (string) ($documentMessage->text ?? ''));
        $this->assertSame('sent', (string) $documentMessage->delivery_status);
        $this->assertSame('wamid-doc-77', (string) ($documentMessage->whatsapp_message_id ?? ''));
        $this->assertSame('application/pdf', (string) ($documentMessage->media_mime_type ?? ''));
        $this->assertStringContainsString('boleto_77_', (string) ($documentMessage->media_filename ?? ''));
    }

    public function test_ixc_invoice_flow_handoffs_after_three_invalid_documents(): void
    {
        $company = $this->createCompanyWithIxcAndInvoiceFlow('Empresa IXC Handoff');
        Area::create([
            'company_id' => (int) $company->id,
            'name' => 'Atendimento',
        ]);
        $admin = $this->createSystemAdmin('sys.ixc.handoff@test.local');

        $this->mockWhatsAppForFlow();

        $this->simulateInbound($admin, $company, '5511999990002', '#')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990002', '1')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990002', 'abc')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990002', '12')->assertOk();
        $third = $this->simulateInbound($admin, $company, '5511999990002', '123');

        $third->assertOk();
        $this->assertStringContainsString('vou te encaminhar para um atendente', mb_strtolower((string) $third->json('reply')));

        $conversation = Conversation::findOrFail((int) $third->json('conversation.id'));
        $this->assertSame('human', (string) $conversation->handling_mode);
        $this->assertSame('area', (string) $conversation->assigned_type);
        $this->assertSame('Atendimento', (string) ($conversation->assigned_area ?? ''));
        $this->assertNull($conversation->bot_flow);
        $this->assertNull($conversation->bot_step);
    }

    public function test_ixc_invoice_flow_blocks_when_document_phone_does_not_match_sender(): void
    {
        $company = $this->createCompanyWithIxcAndInvoiceFlow('Empresa IXC Phone Guard');
        Area::create([
            'company_id' => (int) $company->id,
            'name' => 'Atendimento',
        ]);
        $admin = $this->createSystemAdmin('sys.ixc.phoneguard@test.local');

        $this->mockIxcPhoneMismatchPath();
        $this->mockWhatsAppForFlow();

        $this->simulateInbound($admin, $company, '5511999990003', '#')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990003', '1')->assertOk();
        $first = $this->simulateInbound($admin, $company, '5511999990003', '12345678901');

        $first->assertOk();
        $this->assertStringContainsString(
            'nao confere com o telefone registrado',
            mb_strtolower((string) $first->json('reply'))
        );

        $this->simulateInbound($admin, $company, '5511999990003', '12345678901')->assertOk();
        $third = $this->simulateInbound($admin, $company, '5511999990003', '12345678901');
        $third->assertOk();
        $this->assertStringContainsString('vou te encaminhar para um atendente', mb_strtolower((string) $third->json('reply')));

        $conversation = Conversation::findOrFail((int) $third->json('conversation.id'));
        $this->assertSame('human', (string) $conversation->handling_mode);
        $this->assertSame('area', (string) $conversation->assigned_type);
    }

    public function test_ixc_invoice_flow_can_cancel_before_send(): void
    {
        $company = $this->createCompanyWithIxcAndInvoiceFlow('Empresa IXC Cancel');
        $admin = $this->createSystemAdmin('sys.ixc.cancel@test.local');

        $this->mockIxcSuccessPath();
        $this->mockWhatsAppForFlow();

        $this->simulateInbound($admin, $company, '5511999990004', '#')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990004', '1')->assertOk();

        $confirm = $this->simulateInbound($admin, $company, '5511999990004', '12345678901');
        $confirm->assertOk();
        $this->assertStringContainsString('Deseja que eu envie esse boleto agora?', (string) $confirm->json('reply'));

        $cancel = $this->simulateInbound($admin, $company, '5511999990004', '2');
        $cancel->assertOk();
        $this->assertStringContainsString('Sem problemas', (string) $cancel->json('reply'));

        $conversation = Conversation::findOrFail((int) $cancel->json('conversation.id'));
        $this->assertSame('main', (string) $conversation->bot_flow);
        $this->assertSame('menu', (string) $conversation->bot_step);

        $documentMessage = Message::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('direction', 'out')
            ->where('type', 'bot')
            ->where('content_type', 'document')
            ->latest('id')
            ->first();

        $this->assertNull($documentMessage);
    }

    public function test_ixc_invoice_flow_finds_cnpj_with_formatted_ixc_match(): void
    {
        $company = $this->createCompanyWithIxcAndInvoiceFlow('Empresa IXC CNPJ Format');
        $admin = $this->createSystemAdmin('sys.ixc.cnpjformat@test.local');

        $this->mockIxcFormattedCnpjPath();
        $this->mockWhatsAppForFlow();

        $this->simulateInbound($admin, $company, '5511999990005', '#')->assertOk();
        $this->simulateInbound($admin, $company, '5511999990005', '1')->assertOk();

        $confirm = $this->simulateInbound($admin, $company, '5511999990005', '87277018000113');
        $confirm->assertOk();
        $this->assertStringContainsString('Deseja que eu envie esse boleto agora?', (string) $confirm->json('reply'));

        $final = $this->simulateInbound($admin, $company, '5511999990005', '1');
        $final->assertOk();
        $this->assertStringContainsString('Pronto! Enviei o boleto #88', (string) $final->json('reply'));
    }

    private function mockIxcSuccessPath(): void
    {
        $ixcMock = Mockery::mock(IxcApiService::class)->makePartial();
        $ixcMock->shouldReceive('request')
            ->andReturnUsing(function (Company $company, string $resource, array $params, string $method = 'get'): array {
                unset($company, $params, $method);

                return match ($resource) {
                    'cliente' => [
                        'page' => '1',
                        'total' => 1,
                        'registros' => [[
                            'id' => '19',
                            'razao' => 'Cliente Teste',
                            'cnpj_cpf' => '12345678901',
                        ]],
                    ],
                    'fn_areceber' => [
                        'page' => '1',
                        'total' => 1,
                        'registros' => [[
                            'id' => '77',
                            'id_cliente' => '19',
                            'status' => 'A',
                            'valor' => '99.90',
                            'data_vencimento' => '2026-05-30',
                        ]],
                    ],
                    'get_boleto' => [
                        'pdf_base64' => base64_encode('%PDF-1.4 boleto bot test'),
                    ],
                    default => throw new RuntimeException("Recurso IXC inesperado no teste: {$resource}"),
                };
            });

        $this->app->instance(IxcApiService::class, $ixcMock);
    }

    private function mockIxcPhoneMismatchPath(): void
    {
        $ixcMock = Mockery::mock(IxcApiService::class)->makePartial();
        $ixcMock->shouldReceive('request')
            ->andReturnUsing(function (Company $company, string $resource, array $params, string $method = 'get'): array {
                unset($company, $params, $method);

                if ($resource === 'cliente') {
                    return [
                        'page' => '1',
                        'total' => 1,
                        'registros' => [[
                            'id' => '28',
                            'razao' => 'Cliente Outro Numero',
                            'cnpj_cpf' => '12345678901',
                            'telefone_celular' => '(51) 99888-7777',
                        ]],
                    ];
                }

                throw new RuntimeException("Recurso IXC inesperado no teste: {$resource}");
            });

        $this->app->instance(IxcApiService::class, $ixcMock);
    }

    private function mockIxcFormattedCnpjPath(): void
    {
        $ixcMock = Mockery::mock(IxcApiService::class)->makePartial();
        $ixcMock->shouldReceive('request')
            ->andReturnUsing(function (Company $company, string $resource, array $params, string $method = 'get'): array {
                unset($company, $method);

                if ($resource === 'cliente') {
                    $query = (string) ($params['query'] ?? '');
                    if ($query !== '87.277.018/0001-13') {
                        return ['page' => '1', 'total' => 0, 'registros' => []];
                    }

                    return [
                        'page' => '1',
                        'total' => 1,
                        'registros' => [[
                            'id' => '31',
                            'razao' => 'Cliente CNPJ Formatado',
                            'cnpj_cpf' => '87.277.018/0001-13',
                        ]],
                    ];
                }

                if ($resource === 'fn_areceber') {
                    return [
                        'page' => '1',
                        'total' => 1,
                        'registros' => [[
                            'id' => '88',
                            'id_cliente' => '31',
                            'status' => 'A',
                            'valor' => '49.90',
                            'data_vencimento' => '2026-06-10',
                        ]],
                    ];
                }

                if ($resource === 'get_boleto') {
                    return [
                        'pdf_base64' => base64_encode('%PDF-1.4 boleto cnpj format test'),
                    ];
                }

                throw new RuntimeException("Recurso IXC inesperado no teste: {$resource}");
            });

        $this->app->instance(IxcApiService::class, $ixcMock);
    }

    private function mockWhatsAppForFlow(): void
    {
        $ok = [
            'ok' => true,
            'whatsapp_message_id' => null,
            'status' => 'sent',
            'error' => null,
            'response' => ['simulated' => true],
        ];

        $whatsAppMock = Mockery::mock(WhatsAppSendService::class);
        $whatsAppMock->shouldReceive('sendText')->andReturn($ok)->byDefault();
        $whatsAppMock->shouldReceive('sendInteractiveButtons')->andReturn($ok)->byDefault();
        $whatsAppMock->shouldReceive('sendInteractiveList')->andReturn($ok)->byDefault();
        $whatsAppMock->shouldReceive('uploadMedia')->andReturn(['id' => 'media-doc-77'])->byDefault();
        $whatsAppMock->shouldReceive('sendMedia')->andReturn([
            'ok' => true,
            'whatsapp_message_id' => 'wamid-doc-77',
            'status' => 'sent',
            'error' => null,
            'response' => ['messages' => [['id' => 'wamid-doc-77']]],
        ])->byDefault();
        $whatsAppMock->shouldReceive('downloadInboundImage')->andReturn(null)->byDefault();

        $this->app->instance(WhatsAppSendService::class, $whatsAppMock);
    }

    private function createSystemAdmin(string $email): User
    {
        return User::create([
            'name' => 'System Admin',
            'email' => $email,
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCompanyWithIxcAndInvoiceFlow(string $name): Company
    {
        $company = Company::create([
            'name' => $name,
            'ixc_base_url' => 'https://ixc.exemplo.com/webservice/v1',
            'ixc_api_token' => '4:token-mock-ixc',
            'ixc_enabled' => true,
            'ixc_self_signed' => false,
            'ixc_timeout_seconds' => 15,
        ]);

        CompanyBotSetting::create([
            'company_id' => (int) $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Olá! Escolha uma opção.',
            'fallback_message' => 'Não entendi. Pode reformular?',
            'out_of_hours_message' => 'Fora do horário.',
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
            'stateful_menu_flow' => [
                'commands' => ['#', 'menu'],
                'initial' => ['flow' => 'main', 'step' => 'menu'],
                'steps' => [
                    'main.menu' => [
                        'type' => 'numeric_menu',
                        'reply_text' => "Menu principal\n1 - Boletos",
                        'options' => [
                            '1' => [
                                'label' => 'Boletos',
                                'action' => [
                                    'kind' => 'ixc_invoices_start',
                                    'target_area_name' => 'Atendimento',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return $company;
    }

    private function simulateInbound(User $user, Company $company, string $from, string $text)
    {
        return $this->actingAs($user)->postJson('/api/simular/mensagem', [
            'company_id' => (int) $company->id,
            'from' => $from,
            'text' => $text,
            'send_outbound' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
