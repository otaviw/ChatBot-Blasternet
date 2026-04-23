<?php

if (! function_exists('describe')) {
    final class WhatsAppCredentialsValidatorTest extends \Tests\TestCase
    {
        public function test_pest_runtime_not_loaded(): void
        {
            $this->markTestSkipped('Pest runtime nao carregado neste ambiente.');
        }
    }

    return;
}

use App\Models\Company;
use App\Models\User;
use App\Services\WhatsAppCredentialsValidatorService;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// WhatsAppCredentialsValidatorService — testes unitários com mock HTTP
// ---------------------------------------------------------------------------

describe('WhatsAppCredentialsValidatorService', function () {
    beforeEach(function () {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
    });

    it('retorna ok=true com dados do número quando API responde 200', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/123456789*' => Http::response([
                'display_phone_number' => '+55 11 99999-0000',
                'verified_name'        => 'Empresa Teste',
                'id'                   => '123456789',
            ], 200),
        ]);

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('123456789', 'token-valido');

        expect($result['ok'])->toBeTrue();
        expect($result['display_phone_number'])->toBe('+55 11 99999-0000');
        expect($result['verified_name'])->toBe('Empresa Teste');
        expect($result['error'])->toBeNull();
    });

    it('retorna ok=false com mensagem de token inválido quando API retorna 401', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/123456789*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
            ], 401),
        ]);

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('123456789', 'token-invalido');

        expect($result['ok'])->toBeFalse();
        expect($result['error'])->toBe('Token inválido ou expirado.');
        expect($result['display_phone_number'])->toBeNull();
    });

    it('retorna ok=false quando phone_number_id não existe (API retorna 100)', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/999999999*' => Http::response([
                'error' => ['message' => 'No phone number with id 999999999', 'code' => 100],
            ], 400),
        ]);

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('999999999', 'token-qualquer');

        expect($result['ok'])->toBeFalse();
        expect($result['error'])->toBe('phone_number_id não encontrado ou sem permissão de acesso.');
    });

    it('retorna ok=false com mensagem genérica quando API retorna 403', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/111111111*' => Http::response([], 403),
        ]);

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('111111111', 'token-sem-permissao');

        expect($result['ok'])->toBeFalse();
        expect($result['error'])->toContain('403');
    });

    it('retorna ok=false quando phone_number_id é vazio', function () {
        Http::fake();

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('', 'token-qualquer');

        expect($result['ok'])->toBeFalse();
        Http::assertNothingSent();
    });

    it('retorna ok=false quando access_token é vazio', function () {
        Http::fake();

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('123456789', '');

        expect($result['ok'])->toBeFalse();
        Http::assertNothingSent();
    });

    it('retorna ok=false com mensagem de conexão quando API está inacessível', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $service = app(WhatsAppCredentialsValidatorService::class);
        $result  = $service->validate('123456789', 'token-qualquer');

        expect($result['ok'])->toBeFalse();
        expect($result['error'])->toContain('API da Meta');
    });
});

// ---------------------------------------------------------------------------
// POST /minha-conta/bot/validar-whatsapp — endpoint da empresa
// ---------------------------------------------------------------------------

describe('POST /minha-conta/bot/validar-whatsapp', function () {
    beforeEach(function () {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
        config()->set('whatsapp.app_secret', 'test-secret');
    });

    it('retorna 200 com dados do número quando credenciais são válidas', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/*' => Http::response([
                'display_phone_number' => '+55 11 98888-7777',
                'verified_name'        => 'Bot da Empresa',
            ], 200),
        ]);

        $company = Company::create([
            'name'                 => 'Empresa Validação',
            'meta_phone_number_id' => '555000555',
            'meta_access_token'    => 'token-empresa-ok',
        ]);

        $user = User::create([
            'name'       => 'Admin Bot',
            'email'      => 'bot-valid@test.local',
            'password'   => bcrypt('senha123'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/minha-conta/bot/validar-whatsapp')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('display_phone_number', '+55 11 98888-7777');
    });

    it('retorna 422 quando credenciais são inválidas', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
            ], 401),
        ]);

        $company = Company::create([
            'name'                 => 'Empresa Token Ruim',
            'meta_phone_number_id' => '666000666',
            'meta_access_token'    => 'token-ruim',
        ]);

        $user = User::create([
            'name'       => 'Admin Token Ruim',
            'email'      => 'bot-invalid@test.local',
            'password'   => bcrypt('senha123'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/minha-conta/bot/validar-whatsapp')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    });

    it('aceita phone_number_id e access_token no payload para testar antes de salvar', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/novo-phone-id*' => Http::response([
                'display_phone_number' => '+55 21 97777-6666',
                'verified_name'        => 'Numero Novo',
            ], 200),
        ]);

        $company = Company::create(['name' => 'Empresa Teste Payload']);
        $user    = User::create([
            'name'       => 'Admin Payload',
            'email'      => 'bot-payload@test.local',
            'password'   => bcrypt('senha123'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/minha-conta/bot/validar-whatsapp', [
                'phone_number_id' => 'novo-phone-id',
                'access_token'    => 'novo-token',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    });

    it('retorna 403 para usuário não autenticado', function () {
        $this->postJson('/api/minha-conta/bot/validar-whatsapp')
            ->assertStatus(403);
    });
});

// ---------------------------------------------------------------------------
// PUT /minha-conta/bot — valida credenciais ao salvar quando mudaram
// ---------------------------------------------------------------------------

describe('PUT /minha-conta/bot — validação de credenciais ao salvar', function () {
    beforeEach(function () {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
        config()->set('whatsapp.app_secret', 'test-secret');
    });

    it('rejeita o save com 422 quando novas credenciais são inválidas', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/*' => Http::response([
                'error' => ['message' => 'Invalid token.', 'code' => 190],
            ], 401),
        ]);

        $company = Company::create(['name' => 'Empresa Save Inválido', 'meta_phone_number_id' => '111']);
        $user    = User::create([
            'name'       => 'Operador Save',
            'email'      => 'save-invalid@test.local',
            'password'   => bcrypt('senha123'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $botSetting = \App\Models\CompanyBotSetting::create([
            'company_id'   => $company->id,
            'is_active'    => false,
            'timezone'     => 'America/Sao_Paulo',
            'business_hours' => [],
        ]);

        $this->actingAs($user)
            ->putJson('/api/minha-conta/bot', [
                'meta_phone_number_id' => 'novo-id-invalido',
                'meta_access_token'    => 'token-invalido',
                'is_active'            => false,
                'timezone'             => 'America/Sao_Paulo',
                'business_hours'       => [],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => fn($msg) => str_contains($msg, 'Credenciais do WhatsApp inválidas')]);
    });

    it('salva com sucesso quando novas credenciais são válidas', function () {
        Http::fake([
            'https://graph.facebook.com/v22.0/*' => Http::response([
                'display_phone_number' => '+55 11 90000-1111',
                'verified_name'        => 'Número Novo',
            ], 200),
        ]);

        $company = Company::create(['name' => 'Empresa Save OK', 'meta_phone_number_id' => '000']);
        $user    = User::create([
            'name'       => 'Operador Save OK',
            'email'      => 'save-ok@test.local',
            'password'   => bcrypt('senha123'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->putJson('/api/minha-conta/bot', [
                'meta_phone_number_id' => 'phone-valido-novo',
                'meta_access_token'    => 'token-valido-novo',
                'is_active'            => false,
                'timezone'             => 'America/Sao_Paulo',
                'business_hours'       => [],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        expect($company->fresh()->meta_phone_number_id)->toBe('phone-valido-novo');
    });
});
