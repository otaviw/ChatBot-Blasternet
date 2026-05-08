<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IxcHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite nao habilitada neste ambiente.');
        }

        parent::setUp();
    }

    public function test_agent_without_ixc_permission_is_forbidden(): void
    {
        $company = $this->makeIxcCompany();
        $agent = $this->makeAgent($company->id, permissions: []);

        $response = $this->actingAs($agent)->getJson('/api/minha-conta/ixc/clientes');

        $response->assertStatus(403);
    }

    public function test_company_admin_can_read_ixc_clients(): void
    {
        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake([
            '*' => Http::response([
                'registros' => [
                    ['id' => 1, 'razao' => 'Cliente Teste'],
                ],
                'total' => 1,
            ], 200),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.id', 1);
    }

    public function test_ixc_read_rate_limit_returns_429(): void
    {
        config(['rate_limits.ixc_read' => 2]);

        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake([
            '*' => Http::response([
                'registros' => [],
                'total' => 0,
            ], 200),
        ]);

        $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes')->assertStatus(429);
    }

    public function test_ixc_clients_returns_422_with_clear_message_when_resource_is_unavailable(): void
    {
        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake([
            '*' => Http::response([
                'type' => 'error',
                'message' => 'Recurso cliente nao esta disponivel!',
            ], 200),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJson(fn ($json) => $json
                ->whereType('message', 'string')
                ->where('message', fn ($message) => is_string($message)
                    && (str_contains(mb_strtolower($message), 'disponivel')
                        || str_contains(mb_strtolower($message), 'listagem')))
                ->etc());
    }

    public function test_ixc_clients_search_by_cnpj_can_match_masked_document(): void
    {
        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $query = (string) ($payload['query'] ?? '');
            if ($query === '87.277.018/0001-13') {
                return Http::response([
                    'registros' => [
                        ['id' => 859, 'razao' => 'GADOL ASSESSORIA DE COBRANCAS LTDA', 'cnpj_cpf' => '87.277.018/0001-13'],
                    ],
                    'total' => 1,
                ], 200);
            }

            return Http::response([
                'registros' => [],
                'total' => 0,
            ], 200);
        });

        $response = $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes?q=87277018000113');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.id', 859);
    }

    public function test_ixc_clients_search_keeps_200_when_only_some_attempts_fail(): void
    {
        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $qtype = (string) ($payload['qtype'] ?? '');

            if ($qtype === 'cliente.razao') {
                return Http::response(['error' => 'not found'], 404);
            }

            return Http::response([
                'registros' => [],
                'total' => 0,
            ], 200);
        });

        $response = $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes?q=cliente');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items', []);
    }

    public function test_ixc_clients_search_friendly_message_hides_raw_http_404(): void
    {
        $company = $this->makeIxcCompany();
        $admin = $this->makeCompanyAdmin($company->id);

        Http::fake([
            '*' => Http::response(['error' => 'not found'], 404),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/minha-conta/ixc/clientes?q=87277018000113');

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJson(fn ($json) => $json
                ->where('message', fn ($message) => is_string($message)
                    && ! str_contains($message, 'HTTP 404')
                    && str_contains(mb_strtolower($message), 'recurso'))
                ->etc());
    }

    public function test_ixc_invoices_requires_invoices_view_permission(): void
    {
        $company = $this->makeIxcCompany();
        $agent = $this->makeAgent($company->id, permissions: [
            UserPermissions::PAGE_IXC_CLIENTS,
        ]);

        $this->actingAs($agent)
            ->getJson('/api/minha-conta/ixc/clientes/10/boletos')
            ->assertStatus(403);
    }

    public function test_ixc_invoice_download_requires_download_permission(): void
    {
        $company = $this->makeIxcCompany();
        $agent = $this->makeAgent($company->id, permissions: [
            UserPermissions::PAGE_IXC_CLIENTS,
            UserPermissions::IXC_INVOICES_VIEW,
        ]);

        $this->actingAs($agent)
            ->postJson('/api/minha-conta/ixc/clientes/10/boletos/20/download')
            ->assertStatus(403);
    }

    public function test_ixc_invoice_send_email_requires_send_email_permission(): void
    {
        $company = $this->makeIxcCompany();
        $agent = $this->makeAgent($company->id, permissions: [
            UserPermissions::PAGE_IXC_CLIENTS,
            UserPermissions::IXC_INVOICES_VIEW,
            UserPermissions::IXC_INVOICES_DOWNLOAD,
        ]);

        $this->actingAs($agent)
            ->postJson('/api/minha-conta/ixc/clientes/10/boletos/20/enviar-email', [
                'email' => 'cliente@example.com',
            ])
            ->assertStatus(403);
    }

    public function test_ixc_invoice_send_sms_requires_send_sms_permission(): void
    {
        $company = $this->makeIxcCompany();
        $agent = $this->makeAgent($company->id, permissions: [
            UserPermissions::PAGE_IXC_CLIENTS,
            UserPermissions::IXC_INVOICES_VIEW,
            UserPermissions::IXC_INVOICES_DOWNLOAD,
            UserPermissions::IXC_INVOICES_SEND_EMAIL,
        ]);

        $this->actingAs($agent)
            ->postJson('/api/minha-conta/ixc/clientes/10/boletos/20/enviar-sms', [
                'phone' => '11999999999',
            ])
            ->assertStatus(403);
    }

    private function makeIxcCompany(): Company
    {
        return Company::create([
            'name' => 'Empresa IXC Hardening',
            'ixc_base_url' => 'https://ixc.local/webservice/v1',
            'ixc_api_token' => 'token-test',
            'ixc_self_signed' => true,
            'ixc_timeout_seconds' => 10,
            'ixc_enabled' => true,
        ]);
    }

    private function makeCompanyAdmin(int $companyId): User
    {
        return User::create([
            'name' => 'Admin Empresa',
            'email' => 'admin-ixc@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $companyId,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, string>|null  $permissions
     */
    private function makeAgent(int $companyId, ?array $permissions = null): User
    {
        return User::create([
            'name' => 'Agente IXC',
            'email' => 'agent-ixc@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $companyId,
            'is_active' => true,
            'permissions' => $permissions,
        ]);
    }
}
