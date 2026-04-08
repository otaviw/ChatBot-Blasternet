<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_manage_schedule_and_create_appointment_with_phone_normalization(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-19 08:00:00', 'America/Sao_Paulo'));

        $company = Company::create(['name' => 'Empresa API Agenda']);
        $admin = User::create([
            'name' => 'Admin Agenda',
            'email' => 'admin-agenda@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $agent = User::create([
            'name' => 'Atendente Agenda',
            'email' => 'agent-agenda@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson('/api/minha-conta/agendamentos/configuracoes', [
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 60,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ])->assertOk();

        $serviceResponse = $this->actingAs($admin)->postJson('/api/minha-conta/agendamentos/servicos', [
            'name' => 'Consulta Geral',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);
        $serviceResponse->assertStatus(201);
        $serviceId = (int) $serviceResponse->json('service.id');

        $staffResponse = $this->actingAs($admin)->getJson('/api/minha-conta/agendamentos/atendentes');
        $staffResponse->assertOk();
        $staffProfile = collect($staffResponse->json('staff'))
            ->first(fn(array $item) => (int) ($item['user_id'] ?? 0) === (int) $agent->id);
        $this->assertNotNull($staffProfile);
        $staffProfileId = (int) ($staffProfile['id'] ?? 0);
        $this->assertGreaterThan(0, $staffProfileId);

        $this->actingAs($admin)->putJson("/api/minha-conta/agendamentos/atendentes/{$staffProfileId}/jornada", [
            'hours' => [
                [
                    'day_of_week' => 3,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'is_active' => true,
                ],
            ],
        ])->assertOk();

        $availability = $this->actingAs($admin)->getJson('/api/minha-conta/agendamentos/disponibilidade?service_id=' . $serviceId . '&date=2026-05-20&staff_profile_id=' . $staffProfileId);
        $availability->assertOk();
        $this->assertNotEmpty($availability->json('staff.0.slots'));

        $createResponse = $this->actingAs($admin)->postJson('/api/minha-conta/agendamentos', [
            'service_id' => $serviceId,
            'staff_profile_id' => $staffProfileId,
            'starts_at' => '2026-05-20 10:00:00',
            'customer_name' => 'Cliente API',
            'customer_phone' => '11 8765-4321',
            'source' => 'dashboard',
        ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('appointment.customer_phone', '5511987654321');
        $createResponse->assertJsonPath('appointment.staff_profile_id', $staffProfileId);

        $listResponse = $this->actingAs($admin)->getJson('/api/minha-conta/agendamentos?date_from=2026-05-20&date_to=2026-05-20&customer_phone=1187654321');
        $listResponse->assertOk();
        $this->assertSame(1, count($listResponse->json('items')));
    }
}

