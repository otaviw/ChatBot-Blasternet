<?php

if (! function_exists('describe')) {
    final class EmailMailablesTest extends \Tests\TestCase
    {
        public function test_pest_runtime_not_loaded(): void
        {
            $this->markTestSkipped('Pest runtime nao carregado neste ambiente.');
        }
    }

    return;
}

use App\Jobs\AlertUnattendedConversationsJob;
use App\Jobs\SendAppointmentConfirmedMailJob;
use App\Jobs\SendAppointmentReminderJob;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentReminderMail;
use App\Mail\UnattendedConversationsAlertMail;
use App\Mail\WelcomeUserMail;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentStaffProfile;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Reseller;
use App\Models\User;
use App\Support\AppointmentStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;


describe('WelcomeUserMail', function () {
    it('é enfileirado quando novo usuário ativo é criado pelo admin', function () {
        Mail::fake();

        $reseller = Reseller::create([
            'name' => 'Revenda E-mail',
            'slug' => 'revenda-email',
        ]);
        $actor = User::factory()->create([
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $company = Company::create([
            'name' => 'Empresa Boas Vindas',
            'reseller_id' => $reseller->id,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/admin/users', [
                'name' => 'Novo Usuário',
                'email' => 'novo@empresa.com',
                'password' => 'senha1234',
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => true,
            ])
            ->assertStatus(201);

        Mail::assertQueued(WelcomeUserMail::class, function ($mail) {
            return $mail->hasTo('novo@empresa.com')
                && $mail->plainPassword === 'senha1234';
        });
    });

    it('não é enviado quando usuário é criado inativo', function () {
        Mail::fake();

        $reseller = Reseller::create([
            'name' => 'Revenda Inativo',
            'slug' => 'revenda-inativo',
        ]);
        $actor = User::factory()->create([
            'role' => User::ROLE_RESELLER_ADMIN,
            'reseller_id' => $reseller->id,
            'is_active' => true,
        ]);
        $company = Company::create([
            'name' => 'Empresa Inativo',
            'reseller_id' => $reseller->id,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/admin/users', [
                'name' => 'Usuário Inativo',
                'email' => 'inativo@empresa.com',
                'password' => 'senha1234',
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => false,
            ])
            ->assertStatus(201);

        Mail::assertNotQueued(WelcomeUserMail::class);
    });

    it('renderiza o template sem erros', function () {
        $user = User::factory()->create(['name' => 'Alice', 'email' => 'alice@test.com']);
        $mail = new WelcomeUserMail($user, 'senhaTemporaria123');
        $rendered = $mail->render();

        expect($rendered)->toContain('Alice')
            ->toContain('senhaTemporaria123')
            ->toContain('Bem-vindo');
    });
});


describe('AppointmentConfirmedMail', function () {
    it('renderiza o template com dados do agendamento', function () {
        $company = Company::create(['name' => 'Clínica Test']);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        $appointment = new Appointment([
            'customer_name' => 'João',
            'customer_email' => 'joao@test.com',
            'starts_at' => now()->addDay(),
            'service_duration_minutes' => 30,
            'status' => AppointmentStatus::CONFIRMED,
        ]);
        $appointment->id = 1;
        $appointment->setRelation('service', $service);
        $appointment->setRelation('staffProfile', null);

        $mail = new AppointmentConfirmedMail($appointment, 'America/Sao_Paulo');
        $rendered = $mail->render();

        expect($rendered)->toContain('João')
            ->toContain('Consulta')
            ->toContain('confirmado');
    });
});


describe('SendAppointmentConfirmedMailJob', function () {
    it('envia e-mail quando agendamento tem e-mail e status CONFIRMED', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Clínica Confirmed']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'business_hours' => [],
        ]);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Serviço X',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => User::create([
                'name' => 'Staff Confirmed',
                'email' => 'staff-confirmed@test.local',
                'password' => bcrypt('secret123'),
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => true,
            ])->id,
            'display_name' => 'Dr. Smith',
            'is_bookable' => true,
        ]);

        $appointment = Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'customer_phone' => '5511999999999',
            'customer_email' => 'cliente@test.com',
            'customer_name' => 'Cliente',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'effective_start_at' => now()->addDay(),
            'effective_end_at' => now()->addDay()->addMinutes(30),
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'whatsapp',
        ]);

        (new SendAppointmentConfirmedMailJob($appointment->id))->handle();

        Mail::assertQueued(AppointmentConfirmedMail::class, function ($mail) {
            return $mail->hasTo('cliente@test.com');
        });
    });

    it('não envia quando agendamento não tem e-mail', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Clínica Sem Email']);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Serviço Y',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => User::create([
                'name' => 'Staff No Email',
                'email' => 'staff-no-email@test.local',
                'password' => bcrypt('secret123'),
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => true,
            ])->id,
            'display_name' => 'Dr. Jones',
            'is_bookable' => true,
        ]);

        $appointment = Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'customer_phone' => '5511888888888',
            'customer_email' => null,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'effective_start_at' => now()->addDay(),
            'effective_end_at' => now()->addDay()->addMinutes(30),
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'whatsapp',
        ]);

        (new SendAppointmentConfirmedMailJob($appointment->id))->handle();

        Mail::assertNotQueued(AppointmentConfirmedMail::class);
    });
});


describe('SendAppointmentReminderJob', function () {
    it('envia lembrete para agendamentos confirmados nas próximas 24h', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Clínica Lembrete']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'business_hours' => [],
        ]);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => User::create([
                'name' => 'Staff Reminder',
                'email' => 'staff-reminder@test.local',
                'password' => bcrypt('secret123'),
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => true,
            ])->id,
            'display_name' => 'Dra. Lima',
            'is_bookable' => true,
        ]);

        Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'customer_phone' => '5511777777777',
            'customer_email' => 'reminder@test.com',
            'customer_name' => 'Reminder Test',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(10)->addMinutes(30),
            'effective_start_at' => now()->addHours(10),
            'effective_end_at' => now()->addHours(10)->addMinutes(30),
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'whatsapp',
            'reminder_sent_at' => null,
        ]);

        (new SendAppointmentReminderJob)->handle();

        Mail::assertQueued(AppointmentReminderMail::class, function ($mail) {
            return $mail->hasTo('reminder@test.com');
        });
    });

    it('não reenvia lembrete já enviado', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Clínica Reenvia']);
        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => User::create([
                'name' => 'Staff Reenvio',
                'email' => 'staff-reenvio@test.local',
                'password' => bcrypt('secret123'),
                'role' => User::ROLE_COMPANY_ADMIN,
                'company_id' => $company->id,
                'is_active' => true,
            ])->id,
            'display_name' => 'Dr. Reenvio',
            'is_bookable' => true,
        ]);

        Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'customer_phone' => '5511666666666',
            'customer_email' => 'already@test.com',
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(5)->addMinutes(30),
            'effective_start_at' => now()->addHours(5),
            'effective_end_at' => now()->addHours(5)->addMinutes(30),
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'whatsapp',
            'reminder_sent_at' => now()->subHour(),
        ]);

        (new SendAppointmentReminderJob)->handle();

        Mail::assertNotQueued(AppointmentReminderMail::class);
    });
});


describe('AlertUnattendedConversationsJob', function () {
    it('envia alerta quando há conversas abertas sem resposta além do limite', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Empresa Alerta']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'business_hours' => [],
            'unattended_alert_hours' => 2,
        ]);

        $admin = User::create([
            'name' => 'Admin Alerta',
            'email' => 'admin-alerta@test.local',
            'password' => bcrypt('senha123'),
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999999991',
            'status' => 'open',
            'last_user_message_at' => now()->subHours(3),
            'last_business_message_at' => null,
        ]);

        (new AlertUnattendedConversationsJob)->handle();

        Mail::assertQueued(UnattendedConversationsAlertMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->unattendedCount === 1;
        });
    });

    it('não envia alerta quando todas as conversas foram respondidas', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Empresa Sem Alerta']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'business_hours' => [],
            'unattended_alert_hours' => 2,
        ]);

        User::create([
            'name' => 'Admin Sem Alerta',
            'email' => 'admin-semalerta@test.local',
            'password' => bcrypt('senha123'),
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999999992',
            'status' => 'open',
            'last_user_message_at' => now()->subHours(3),
            'last_business_message_at' => now()->subHour(),
        ]);

        (new AlertUnattendedConversationsJob)->handle();

        Mail::assertNotQueued(UnattendedConversationsAlertMail::class);
    });

    it('não envia alerta quando empresa não tem unattended_alert_hours configurado', function () {
        Mail::fake();

        $company = Company::create(['name' => 'Empresa Sem Config']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'business_hours' => [],
            'unattended_alert_hours' => null,
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999999993',
            'status' => 'open',
            'last_user_message_at' => now()->subHours(5),
            'last_business_message_at' => null,
        ]);

        (new AlertUnattendedConversationsJob)->handle();

        Mail::assertNotQueued(UnattendedConversationsAlertMail::class);
    });
});
