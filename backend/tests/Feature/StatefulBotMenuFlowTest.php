<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentWorkingHour;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Reseller;
use App\Models\ResellerAiCompanyPermission;
use App\Models\User;
use App\Services\Ai\ChatbotAiIntentClassifier;
use App\Services\Ai\ChatbotAiDecisionService;
use Carbon\CarbonImmutable;
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
        $response->assertJsonPath('reply', 'Opção inválida. Responda com 1, 2, 3... ou "menu" para voltar ao menu principal.');

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
        $second->assertJsonPath('reply', 'Opção inválida. Responda com 1, 2, 3... ou "menu" para voltar ao menu principal.');
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
        $start->assertJsonPath('reply', "Oi. Como posso ajudar?\n\nMenu principal\n1 - Financeiro\n2 - Suporte");

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

    public function test_menu_shows_appointment_option_and_books_slot(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'America/Sao_Paulo'));

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Agenda Bot');
        Area::create([
            'company_id' => $company->id,
            'name' => 'Atendimento',
        ]);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);
        $staffUser = User::create([
            'name' => 'Atendente Agenda',
            'email' => 'staff-agenda@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $staffUser->id,
            'display_name' => 'Atendente Agenda',
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
        ]);
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
        ]);

        $user = $this->createCompanyUser($company, 'agenda-flow@test.local');

        $start = $this->simulateInbound($user, $company, '551187654321', '#');
        $start->assertOk();
        $this->assertStringContainsString('4 - Marcar agendamento', (string) $start->json('reply'));

        $toDays = $this->simulateInbound($user, $company, '551187654321', '4');
        $toDays->assertOk();
        $this->assertStringContainsString('Qual dia você prefere', (string) $toDays->json('reply'));

        $toSlots = $this->simulateInbound($user, $company, '551187654321', 'segunda');
        $toSlots->assertOk();
        $this->assertStringContainsString('Horários de', (string) $toSlots->json('reply'));

        $toConfirm = $this->simulateInbound($user, $company, '551187654321', '1');
        $toConfirm->assertOk();
        $this->assertStringContainsString('informe seu endereço de e-mail', (string) $toConfirm->json('reply'));

        $toConfirm = $this->simulateInbound($user, $company, '551187654321', 'pular');
        $toConfirm->assertOk();
        $this->assertStringContainsString('Confirma o agendamento?', (string) $toConfirm->json('reply'));

        $confirmed = $this->simulateInbound($user, $company, '551187654321', '1');
        $confirmed->assertOk();
        $this->assertStringContainsString('Agendamento confirmado', (string) $confirmed->json('reply'));

        $appointment = Appointment::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame((int) $service->id, (int) $appointment->service_id);
        $this->assertSame((int) $staffProfile->id, (int) $appointment->staff_profile_id);
        $this->assertSame('5511987654321', (string) $appointment->customer_phone);
        $this->assertNull($appointment->customer_name);
        $this->assertNull($appointment->customer_email);
    }

    public function test_appointment_flow_allows_handoff_with_option_nine(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'America/Sao_Paulo'));

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Agenda Handoff');
        Area::create([
            'company_id' => $company->id,
            'name' => 'Atendimento',
        ]);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);
        AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);

        $user = $this->createCompanyUser($company, 'agenda-handoff@test.local');

        $this->simulateInbound($user, $company, '5511999990009', '#')->assertOk();
        $this->simulateInbound($user, $company, '5511999990009', '4')->assertOk();
        $handoff = $this->simulateInbound($user, $company, '5511999990009', '9');

        $handoff->assertOk();
        $handoff->assertJsonPath('reply', 'Certo. Vou te encaminhar para um atendente.');

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('area', $conversation->assigned_type);
        $this->assertSame('Atendimento', $conversation->assigned_area);
    }

    public function test_appointment_flow_accepts_day_and_time_in_same_message(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'America/Sao_Paulo'));

        $fixture = $this->createAppointmentFlowFixture('copilot-direct');
        $company = $fixture['company'];
        $user = $fixture['user'];
        $service = $fixture['service'];
        $staffProfile = $fixture['staffProfile'];

        $this->simulateInbound($user, $company, '5511888810001', '#')->assertOk();
        $this->simulateInbound($user, $company, '5511888810001', '4')->assertOk();

        $emailStep = $this->simulateInbound($user, $company, '5511888810001', 'segunda 09:00');
        $emailStep->assertOk();
        $this->assertStringContainsString('e-mail', (string) $emailStep->json('reply'));

        $conversation = Conversation::findOrFail((int) $emailStep->json('conversation.id'));
        $this->assertSame('appointments', $conversation->bot_flow);
        $this->assertSame('collect_email', $conversation->bot_step);
        $this->assertStringContainsString('09:00', (string) ($conversation->bot_context['appointment']['slot_starts_at'] ?? ''));

        $confirmStep = $this->simulateInbound($user, $company, '5511888810001', 'sem email');
        $confirmStep->assertOk();
        $this->assertStringContainsString('Confirma o agendamento?', (string) $confirmStep->json('reply'));

        $done = $this->simulateInbound($user, $company, '5511888810001', 'isso mesmo, muito obrigado');
        $done->assertOk();
        $this->assertStringContainsString('Agendamento confirmado', (string) $done->json('reply'));

        $appointment = Appointment::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($appointment);
        $this->assertSame((int) $service->id, (int) $appointment->service_id);
        $this->assertSame((int) $staffProfile->id, (int) $appointment->staff_profile_id);
        $this->assertNull($appointment->customer_name);
    }

    public function test_ai_first_message_with_appointment_day_period_goes_direct_to_filtered_slots_with_welcome(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'America/Sao_Paulo'));
        config()->set([
            'ai.chatbot_feature_enabled' => true,
            'ai.provider' => 'test',
            'ai.model' => 'test-model',
        ]);

        $fixture = $this->createAppointmentFlowFixture('ai-direct-period');
        $company = $fixture['company'];
        $user = $fixture['user'];
        $staffProfile = $fixture['staffProfile'];

        $reseller = Reseller::create(['name' => 'Reseller Agenda IA', 'slug' => 'reseller-agenda-ia']);
        $company->reseller_id = (int) $reseller->id;
        $company->save();
        ResellerAiCompanyPermission::create([
            'reseller_id' => (int) $reseller->id,
            'company_id' => (int) $company->id,
            'ai_chatbot_allowed' => true,
            'allowed_at' => now(),
        ]);
        $company->botSetting()->update([
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_ALWAYS,
            'ai_chatbot_confidence_threshold' => 0.75,
            'ai_chatbot_auto_reply_enabled' => true,
        ]);

        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 2,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        $response = $this->simulateInbound(
            $user,
            $company,
            '5511888810101',
            'oi, queria ver os horarios de amanha a tarde'
        );

        $response->assertOk();
        $reply = (string) $response->json('reply');
        $this->assertStringStartsWith('Oi. Como posso ajudar?', $reply);
        $this->assertStringContainsString('Vou te passar os horarios de amanha a tarde disponiveis:', $reply);
        $this->assertStringContainsString('Hor', $reply);
        $this->assertStringContainsString('13:', $reply);
        $this->assertStringNotContainsString('09:', $reply);

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $this->assertSame('appointments', $conversation->bot_flow);
        $this->assertSame('slot_select', $conversation->bot_step);
        $this->assertSame('2026-05-19', (string) ($conversation->bot_context['appointment']['selected_date'] ?? ''));
        $this->assertSame('afternoon', (string) ($conversation->bot_context['appointment']['slot_period'] ?? ''));
    }

    public function test_appointment_flow_accepts_time_text_and_handoffs_after_repeated_invalid_slots(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'America/Sao_Paulo'));

        $fixture = $this->createAppointmentFlowFixture('copilot-slot');
        $company = $fixture['company'];
        $user = $fixture['user'];

        $this->simulateInbound($user, $company, '5511888810002', '#')->assertOk();
        $this->simulateInbound($user, $company, '5511888810002', '4')->assertOk();
        $this->simulateInbound($user, $company, '5511888810002', 'segunda')->assertOk();

        $emailStep = $this->simulateInbound($user, $company, '5511888810002', 'quero 09h');
        $emailStep->assertOk();
        $this->assertStringContainsString('e-mail', (string) $emailStep->json('reply'));

        $this->simulateInbound($user, $company, '5511888810003', '#')->assertOk();
        $this->simulateInbound($user, $company, '5511888810003', '4')->assertOk();
        $this->simulateInbound($user, $company, '5511888810003', 'segunda')->assertOk();
        $this->simulateInbound($user, $company, '5511888810003', 'de noite')->assertOk();
        $this->simulateInbound($user, $company, '5511888810003', 'mais tarde')->assertOk();

        $handoff = $this->simulateInbound($user, $company, '5511888810003', 'qualquer coisa');
        $handoff->assertOk();
        $this->assertStringContainsString('encaminhar para um atendente', (string) $handoff->json('reply'));

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('Atendimento', $conversation->assigned_area);
    }

    public function test_numeric_menu_accepts_option_label_text(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Menu Texto');
        Area::create([
            'company_id' => $company->id,
            'name' => 'Suporte',
        ]);
        $user = $this->createCompanyUser($company, 'menu-texto@test.local');

        $this->simulateInbound($user, $company, '5511888810004', '#')->assertOk();

        $supportMenu = $this->simulateInbound($user, $company, '5511888810004', 'Suporte técnico');
        $supportMenu->assertOk();
        $this->assertStringContainsString('Qual o problema?', (string) $supportMenu->json('reply'));

        $handoff = $this->simulateInbound($user, $company, '5511888810004', 'internet lenta');
        $handoff->assertOk();
        $this->assertStringContainsString('internet lenta', (string) $handoff->json('reply'));

        $conversation = Conversation::findOrFail((int) $handoff->json('conversation.id'));
        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('Suporte', $conversation->assigned_area);
    }

    public function test_ai_transfers_out_of_scope_intent_to_closest_area(): void
    {
        config()->set([
            'ai.chatbot_feature_enabled' => true,
            'ai.provider' => 'test',
            'ai.model' => 'test-model',
        ]);

        $statefulMenuFlow = [
            'commands' => ['#', 'menu'],
            'initial' => ['flow' => 'main', 'step' => 'menu'],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Menu principal\n1 - Financeiro",
                    'options' => [
                        '1' => [
                            'label' => 'Financeiro',
                            'action' => [
                                'kind' => 'handoff',
                                'target_area_name' => 'Financeiro',
                                'reply_text' => 'Vou te encaminhar para o Financeiro.',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $reseller = Reseller::create(['name' => 'Reseller IA', 'slug' => 'reseller-ia']);
        $company = $this->createCompanyWithAlwaysOnBot('Empresa IA Fora Escopo', [
            'stateful_menu_flow' => $statefulMenuFlow,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_ALWAYS,
            'ai_chatbot_confidence_threshold' => 0.75,
            'ai_chatbot_auto_reply_enabled' => false,
        ]);
        $company->reseller_id = (int) $reseller->id;
        $company->save();

        Area::create(['company_id' => $company->id, 'name' => 'Suporte']);
        ResellerAiCompanyPermission::create([
            'reseller_id' => (int) $reseller->id,
            'company_id' => (int) $company->id,
            'ai_chatbot_allowed' => true,
            'allowed_at' => now(),
        ]);

        $this->mock(ChatbotAiIntentClassifier::class, function ($mock): void {
            $mock->shouldReceive('classify')
                ->once()
                ->andReturn([
                    'intent' => 'suporte_tecnico',
                    'confidence' => 0.95,
                    'extracted_data' => [],
                    'suggested_reply' => null,
                    'should_transfer_to_human' => true,
                    'reason' => 'outside_company_scope',
                ]);
        });

        $user = $this->createCompanyUser($company, 'ai-out-of-scope@test.local');
        $response = $this->simulateInbound($user, $company, '5511911110010', 'minha internet caiu');

        $response->assertOk();
        $response->assertJsonPath('reply', "Oi. Como posso ajudar?\n\nNao consegui resolver isso com o autoatendimento desta empresa. Vou te encaminhar para Suporte.");

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $supportArea = Area::query()
            ->where('company_id', $company->id)
            ->where('name', 'Suporte')
            ->firstOrFail();

        $this->assertSame('human', $conversation->handling_mode);
        $this->assertSame('area', $conversation->assigned_type);
        $this->assertSame((int) $supportArea->id, (int) $conversation->assigned_id);
        $this->assertSame('Suporte', $conversation->assigned_area);
    }

    public function test_natural_text_from_menu_routes_to_configured_options_without_numeric_input(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-20 14:00:00', 'America/Sao_Paulo'));

        $statefulMenuFlow = [
            'commands' => ['#', 'menu'],
            'initial' => ['flow' => 'main', 'step' => 'menu'],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Oi. Como posso ajudar?\n1 - Financeiro\n2 - Vendas\n3 - Suporte tecnico\n4 - Falar com atendente\n5 - Marcar agendamento",
                    'options' => [
                        '1' => [
                            'label' => 'Financeiro',
                            'action' => ['kind' => 'go_to', 'flow' => 'finance', 'step' => 'menu'],
                        ],
                        '2' => [
                            'label' => 'Vendas',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Vendas', 'reply_text' => 'Vou te encaminhar para Vendas.'],
                        ],
                        '3' => [
                            'label' => 'Suporte tecnico',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Suporte', 'reply_text' => 'Vou te encaminhar para Suporte.'],
                        ],
                        '4' => [
                            'label' => 'Falar com atendente',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Atendimento', 'reply_text' => 'Vou te encaminhar para Atendimento.'],
                        ],
                        '5' => [
                            'label' => 'Marcar agendamento',
                            'action' => ['kind' => 'appointments_start', 'target_area_name' => 'Atendimento'],
                        ],
                    ],
                ],
                'finance.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Escolha uma das opções para seguir atendimento.\n1 - Segunda via boleto\n2 - Nota fiscal\n3 - Falar com financeiro",
                    'options' => [
                        '1' => [
                            'label' => 'Segunda via boleto',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Financeiro', 'reply_text' => 'Vou te encaminhar para o Financeiro.'],
                        ],
                        '2' => [
                            'label' => 'Nota fiscal',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Financeiro', 'reply_text' => 'Vou te encaminhar para o Financeiro.'],
                        ],
                        '3' => [
                            'label' => 'Falar com financeiro',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Financeiro', 'reply_text' => 'Vou te encaminhar para o Financeiro.'],
                        ],
                    ],
                ],
            ],
        ];

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Texto Natural', [
            'stateful_menu_flow' => $statefulMenuFlow,
        ]);
        $fixture = $this->attachAppointmentAvailability($company, 'natural-menu');
        Area::create(['company_id' => $company->id, 'name' => 'Financeiro']);
        Area::create(['company_id' => $company->id, 'name' => 'Atendimento']);

        $user = $this->createCompanyUser($company, 'natural-menu@test.local');

        $this->simulateInbound($user, $company, '5511777700010', 'menu')->assertOk();
        $boleto = $this->simulateInbound($user, $company, '5511777700010', 'quero pegar boleto');
        $boleto->assertOk();
        $this->assertStringNotContainsString('Opção inválida', (string) $boleto->json('reply'));
        $this->assertStringContainsString('Segunda via boleto', (string) $boleto->json('reply'));

        $this->simulateInbound($user, $company, '5511777700011', 'menu')->assertOk();
        $agenda = $this->simulateInbound($user, $company, '5511777700011', 'agendar horario pra domingo');
        $agenda->assertOk();
        $this->assertStringNotContainsString('Opção inválida', (string) $agenda->json('reply'));

        $conversation = Conversation::findOrFail((int) $agenda->json('conversation.id'));
        $this->assertSame('appointments', $conversation->bot_flow);

        $this->assertNotNull($fixture['service']);
    }

    public function test_first_message_natural_text_routes_before_showing_plain_menu(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-20 14:00:00', 'America/Sao_Paulo'));

        $statefulMenuFlow = [
            'commands' => ['#', 'menu'],
            'initial' => ['flow' => 'main', 'step' => 'menu'],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Oi. Como posso ajudar?\n1 - Financeiro\n2 - Vendas\n3 - Suporte tecnico\n4 - Falar com atendente\n5 - Marcar agendamento",
                    'options' => [
                        '1' => [
                            'label' => 'Financeiro',
                            'action' => ['kind' => 'go_to', 'flow' => 'finance', 'step' => 'menu'],
                        ],
                        '5' => [
                            'label' => 'Marcar agendamento',
                            'action' => ['kind' => 'appointments_start', 'target_area_name' => 'Atendimento'],
                        ],
                    ],
                ],
                'finance.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Escolha uma das opções para seguir atendimento.\n1 - Segunda via boleto",
                    'options' => [
                        '1' => [
                            'label' => 'Segunda via boleto',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Financeiro', 'reply_text' => 'Vou te encaminhar para o Financeiro.'],
                        ],
                    ],
                ],
            ],
        ];

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Primeira Natural', [
            'stateful_menu_flow' => $statefulMenuFlow,
        ]);
        $this->attachAppointmentAvailability($company, 'first-natural');
        Area::create(['company_id' => $company->id, 'name' => 'Financeiro']);
        Area::create(['company_id' => $company->id, 'name' => 'Atendimento']);

        $user = $this->createCompanyUser($company, 'first-natural@test.local');

        $boleto = $this->simulateInbound($user, $company, '5511777700040', 'quero boleto');
        $boleto->assertOk();
        $this->assertStringContainsString('Segunda via boleto', (string) $boleto->json('reply'));
        $this->assertStringNotContainsString("5 - Marcar agendamento", (string) $boleto->json('reply'));

        $typoAppointment = $this->simulateInbound($user, $company, '5511777700041', 'egndamento');
        $typoAppointment->assertOk();
        $this->assertStringContainsString('Qual dia', (string) $typoAppointment->json('reply'));
    }

    public function test_active_appointment_flow_can_switch_back_to_finance_and_menu_with_natural_text(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-20 08:00:00', 'America/Sao_Paulo'));

        $statefulMenuFlow = [
            'commands' => ['#', 'menu'],
            'initial' => ['flow' => 'main', 'step' => 'menu'],
            'steps' => [
                'main.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Oi. Como posso ajudar?\n1 - Financeiro\n5 - Marcar agendamento",
                    'options' => [
                        '1' => [
                            'label' => 'Financeiro',
                            'action' => ['kind' => 'go_to', 'flow' => 'finance', 'step' => 'menu'],
                        ],
                        '5' => [
                            'label' => 'Marcar agendamento',
                            'action' => ['kind' => 'appointments_start', 'target_area_name' => 'Atendimento'],
                        ],
                    ],
                ],
                'finance.menu' => [
                    'type' => 'numeric_menu',
                    'reply_text' => "Escolha uma das opções para seguir atendimento.\n1 - Segunda via boleto",
                    'options' => [
                        '1' => [
                            'label' => 'Segunda via boleto',
                            'action' => ['kind' => 'handoff', 'target_area_name' => 'Financeiro', 'reply_text' => 'Vou te encaminhar para o Financeiro.'],
                        ],
                    ],
                ],
            ],
        ];

        $company = $this->createCompanyWithAlwaysOnBot('Empresa Troca Financeiro', [
            'stateful_menu_flow' => $statefulMenuFlow,
        ]);
        $fixture = $this->attachAppointmentAvailability($company, 'switch-finance');
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $fixture['staffProfile']->id,
            'day_of_week' => 2,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);
        Area::create(['company_id' => $company->id, 'name' => 'Financeiro']);
        Area::create(['company_id' => $company->id, 'name' => 'Atendimento']);

        $user = $this->createCompanyUser($company, 'switch-finance@test.local');

        $this->simulateInbound($user, $company, '5511777700050', 'agendamento')->assertOk();
        $this->simulateInbound($user, $company, '5511777700050', 'terca a tarde')->assertOk();

        $finance = $this->simulateInbound($user, $company, '5511777700050', 'quero voltar pro financeiro');
        $finance->assertOk();
        $this->assertStringContainsString('Segunda via boleto', (string) $finance->json('reply'));

        $menu = $this->simulateInbound($user, $company, '5511777700051', 'agendamento');
        $menu->assertOk();
        $this->simulateInbound($user, $company, '5511777700051', 'terca a tarde')->assertOk();
        $back = $this->simulateInbound($user, $company, '5511777700051', 'quero voltar para o menu');
        $back->assertOk();
        $back->assertJsonPath('reply', "Oi. Como posso ajudar?\n1 - Financeiro\n5 - Marcar agendamento");
    }

    public function test_natural_start_commands_reset_to_main_menu_from_subflow(): void
    {
        $company = $this->createCompanyWithAlwaysOnBot('Empresa Inicio');
        $user = $this->createCompanyUser($company, 'inicio@test.local');

        $this->simulateInbound($user, $company, '5511777700020', 'menu')->assertOk();
        $this->simulateInbound($user, $company, '5511777700020', '1')->assertOk();

        $response = $this->simulateInbound($user, $company, '5511777700020', 'quero voltar pro inicio');
        $response->assertOk();
        $response->assertJsonPath('reply', $this->mainMenuText('Oi. Como posso ajudar?'));

        $conversation = Conversation::findOrFail((int) $response->json('conversation.id'));
        $this->assertSame('main', $conversation->bot_flow);
        $this->assertSame('menu', $conversation->bot_step);
    }

    public function test_appointment_slot_step_accepts_period_and_new_date_text(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-20 08:00:00', 'America/Sao_Paulo'));

        $fixture = $this->createAppointmentFlowFixture('slot-period-date');
        $company = $fixture['company'];
        $user = $fixture['user'];
        $staffProfile = $fixture['staffProfile'];

        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
        ]);
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 2,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 4,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        $this->simulateInbound($user, $company, '5511777700030', 'menu')->assertOk();
        $this->simulateInbound($user, $company, '5511777700030', '4')->assertOk();
        $this->simulateInbound($user, $company, '5511777700030', 'terça')->assertOk();

        $period = $this->simulateInbound($user, $company, '5511777700030', 'a tarde');
        $period->assertOk();
        $this->assertStringNotContainsString('Opcao invalida', (string) $period->json('reply'));
        $this->assertStringContainsString('13:', (string) $period->json('reply'));
        $this->assertStringNotContainsString('09:', (string) $period->json('reply'));

        $newDate = $this->simulateInbound($user, $company, '5511777700030', 'quero dia 04/06');
        $newDate->assertOk();
        $this->assertStringNotContainsString('Vou te encaminhar para um atendente', (string) $newDate->json('reply'));
        $this->assertStringContainsString('04/06', (string) $newDate->json('reply'));

        $conversation = Conversation::findOrFail((int) $newDate->json('conversation.id'));
        $this->assertSame('appointments', $conversation->bot_flow);
        $this->assertSame('slot_select', $conversation->bot_step);
        $this->assertSame('2026-06-04', (string) ($conversation->bot_context['appointment']['selected_date'] ?? ''));
    }

    /**
     * @return array{company: Company, user: User, service: AppointmentService, staffProfile: AppointmentStaffProfile}
     */
    private function createAppointmentFlowFixture(string $suffix): array
    {
        $company = $this->createCompanyWithAlwaysOnBot("Empresa Agenda {$suffix}");
        Area::create([
            'company_id' => $company->id,
            'name' => 'Atendimento',
        ]);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);
        $staffUser = User::create([
            'name' => 'Atendente Agenda',
            'email' => "staff-agenda-{$suffix}@test.local",
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $staffUser->id,
            'display_name' => 'Atendente Agenda',
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
        ]);
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
        ]);

        $user = $this->createCompanyUser($company, "agenda-{$suffix}@test.local");

        return [
            'company' => $company,
            'user' => $user,
            'service' => $service,
            'staffProfile' => $staffProfile,
        ];
    }

    /**
     * @return array{service: AppointmentService, staffProfile: AppointmentStaffProfile}
     */
    private function attachAppointmentAvailability(Company $company, string $suffix): array
    {
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => false,
        ]);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);
        $staffUser = User::create([
            'name' => 'Atendente Natural',
            'email' => "staff-natural-{$suffix}@test.local",
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $staffUser->id,
            'display_name' => 'Atendente Natural',
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
        ]);
        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 0,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_active' => true,
        ]);

        return ['service' => $service, 'staffProfile' => $staffProfile];
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
            'role' => User::ROLE_COMPANY_ADMIN,
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

