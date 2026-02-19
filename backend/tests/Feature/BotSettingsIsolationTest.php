<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotSettingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite nao habilitada neste ambiente.');
        }

        parent::setUp();
    }

    public function test_company_sees_only_its_own_bot_settings(): void
    {
        [$companyA, $companyB] = $this->createTwoCompaniesWithSettings();
        $user = User::create([
            'name' => 'Empresa A User',
            'email' => 'empresa-a@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/bot');

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonPath('company.id', $companyA->id);
        $response->assertJsonPath('settings.welcome_message', 'Boas-vindas A');

        $payload = $response->json();
        $this->assertNotSame('Boas-vindas B', $payload['settings']['welcome_message'] ?? null);
        $this->assertNotSame($companyB->id, $payload['company']['id'] ?? null);
    }

    public function test_company_updates_only_its_own_settings(): void
    {
        [$companyA, $companyB] = $this->createTwoCompaniesWithSettings();
        $user = User::create([
            'name' => 'Empresa A User 2',
            'email' => 'empresa-a2@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $payload = $this->validSettingsPayload([
            'welcome_message' => 'Novo texto da Empresa A',
            'fallback_message' => 'Fallback A atualizado',
        ]);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $payload);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('settings.company_id', $companyA->id);

        $this->assertDatabaseHas('company_bot_settings', [
            'company_id' => $companyA->id,
            'welcome_message' => 'Novo texto da Empresa A',
            'fallback_message' => 'Fallback A atualizado',
        ]);

        $this->assertDatabaseHas('company_bot_settings', [
            'company_id' => $companyB->id,
            'welcome_message' => 'Boas-vindas B',
            'fallback_message' => 'Fallback B',
        ]);
    }

    public function test_company_cannot_access_admin_company_endpoints(): void
    {
        [, $companyB] = $this->createTwoCompaniesWithSettings();
        $companyA = Company::where('name', 'Empresa A')->firstOrFail();
        $user = User::create([
            'name' => 'Empresa User',
            'email' => 'empresa-user@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $companyA->id,
            'is_active' => true,
        ]);

        $showResponse = $this->actingAs($user)->getJson("/api/admin/empresas/{$companyB->id}");

        $showResponse->assertStatus(403);
        $showResponse->assertJsonPath('redirect', '/entrar');

        $updateResponse = $this->actingAs($user)->putJson("/api/admin/empresas/{$companyB->id}/bot", $this->validSettingsPayload([
            'welcome_message' => 'Tentativa indevida',
        ]));

        $updateResponse->assertStatus(403);
        $updateResponse->assertJsonPath('redirect', '/entrar');
    }

    public function test_admin_can_view_and_update_any_company_settings(): void
    {
        [, $companyB] = $this->createTwoCompaniesWithSettings();
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => 'secret123',
            'role' => 'admin',
            'company_id' => null,
            'is_active' => true,
        ]);

        $showResponse = $this->actingAs($admin)->getJson("/api/admin/empresas/{$companyB->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('authenticated', true);
        $showResponse->assertJsonPath('company.id', $companyB->id);
        $showResponse->assertJsonPath('company.bot_setting.welcome_message', 'Boas-vindas B');

        $updateResponse = $this->actingAs($admin)->putJson("/api/admin/empresas/{$companyB->id}/bot", $this->validSettingsPayload([
            'is_active' => false,
            'welcome_message' => 'Ajustado pelo admin',
        ]));

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('ok', true);
        $updateResponse->assertJsonPath('settings.company_id', $companyB->id);
        $updateResponse->assertJsonPath('settings.is_active', false);
        $updateResponse->assertJsonPath('settings.welcome_message', 'Ajustado pelo admin');

        $this->assertDatabaseHas('company_bot_settings', [
            'company_id' => $companyB->id,
            'is_active' => 0,
            'welcome_message' => 'Ajustado pelo admin',
        ]);
    }

    private function createTwoCompaniesWithSettings(): array
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        CompanyBotSetting::create([
            'company_id' => $companyA->id,
            ...$this->validSettingsPayload([
                'welcome_message' => 'Boas-vindas A',
                'fallback_message' => 'Fallback A',
            ]),
        ]);

        CompanyBotSetting::create([
            'company_id' => $companyB->id,
            ...$this->validSettingsPayload([
                'welcome_message' => 'Boas-vindas B',
                'fallback_message' => 'Fallback B',
            ]),
        ]);

        return [$companyA, $companyB];
    }

    private function validSettingsPayload(array $overrides = []): array
    {
        $base = [
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi. Como posso ajudar?',
            'fallback_message' => 'Nao entendi sua mensagem.',
            'out_of_hours_message' => 'Estamos fora do horario de atendimento.',
            'business_hours' => [
                'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'saturday' => ['enabled' => false, 'start' => null, 'end' => null],
                'sunday' => ['enabled' => false, 'start' => null, 'end' => null],
            ],
            'keyword_replies' => [
                ['keyword' => 'preco', 'reply' => 'Consulte nossos planos atuais.'],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }
}
