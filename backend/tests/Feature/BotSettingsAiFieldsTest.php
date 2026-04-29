<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\WhatsAppCredentialsValidatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BotSettingsAiFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite não habilitada neste ambiente.');
        }

        parent::setUp();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeCompanyUser(string $suffix = 'a'): array
    {
        $company = Company::create(['name' => "Empresa AI {$suffix}"]);

        // ai_enabled=true é necessário para canManageAi() retornar true para company_admin
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
        ]);

        $user = User::create([
            'name' => "User AI {$suffix}",
            'email' => "user-ai-{$suffix}@test.local",
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN, // normaliza para company_admin
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    /** Payload mínimo válido para o endpoint (campos obrigatórios do bot clássico). */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'welcome_message' => 'Oi.',
            'fallback_message' => 'Não entendi.',
            'out_of_hours_message' => 'Fora do horario.',
            'message_retention_days' => 180,
            'business_hours' => [
                'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'saturday' => ['enabled' => false, 'start' => null, 'end' => null],
                'sunday' => ['enabled' => false, 'start' => null, 'end' => null],
            ],
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Testes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ai_fields_are_persisted_when_sent(): void
    {
        [, $user] = $this->makeCompanyUser('persist');

        $payload = $this->basePayload([
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_usage_enabled' => false,
            'ai_usage_limit_monthly' => 500,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_auto_reply_enabled' => true,
            'ai_chatbot_mode' => 'fallback',
            'ai_persona' => 'Atendente simpático',
            'ai_tone' => 'formal',
            'ai_language' => 'pt-BR',
            'ai_formality' => 'alta',
            'ai_system_prompt' => 'Você é um assistente de suporte.',
            'ai_max_context_messages' => 15,
            'ai_temperature' => 0.7,
            'ai_max_response_tokens' => 1024,
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-sonnet-4-6',
            'ai_chatbot_rules' => ['Não mencione concorrentes', 'Sempre se despedir'],
        ]);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $payload);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('settings.ai_enabled', true);
        $response->assertJsonPath('settings.ai_chatbot_mode', 'fallback');
        $response->assertJsonPath('settings.ai_usage_limit_monthly', 500);
        $response->assertJsonPath('settings.ai_temperature', 0.7);
        $response->assertJsonPath('settings.ai_max_context_messages', 15);
        $response->assertJsonPath('settings.ai_model', 'claude-sonnet-4-6');

        $this->assertDatabaseHas('company_bot_settings', [
            'ai_enabled' => true,
            'ai_internal_chat_enabled' => true,
            'ai_usage_enabled' => false,
            'ai_usage_limit_monthly' => 500,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_auto_reply_enabled' => true,
            'ai_chatbot_mode' => 'fallback',
            'ai_persona' => 'Atendente simpático',
            'ai_tone' => 'formal',
            'ai_language' => 'pt-BR',
            'ai_formality' => 'alta',
            'ai_system_prompt' => 'Você é um assistente de suporte.',
            'ai_max_context_messages' => 15,
            'ai_max_response_tokens' => 1024,
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-sonnet-4-6',
        ]);
    }

    public function test_invalid_ai_chatbot_mode_returns_422(): void
    {
        [, $user] = $this->makeCompanyUser('mode');

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'ai_chatbot_mode' => 'invalid_value',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ai_chatbot_mode']);
    }

    public function test_ai_usage_limit_monthly_zero_returns_422(): void
    {
        [, $user] = $this->makeCompanyUser('limit');

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'ai_usage_limit_monthly' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ai_usage_limit_monthly']);
    }

    public function test_ai_temperature_out_of_range_returns_422(): void
    {
        [, $user] = $this->makeCompanyUser('temp');

        $responseLow = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'ai_temperature' => -0.1,
        ]));
        $responseLow->assertStatus(422);
        $responseLow->assertJsonValidationErrors(['ai_temperature']);

        $responseHigh = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'ai_temperature' => 2.1,
        ]));
        $responseHigh->assertStatus(422);
        $responseHigh->assertJsonValidationErrors(['ai_temperature']);
    }

    public function test_get_returns_persisted_ai_fields(): void
    {
        [$company, $user] = $this->makeCompanyUser('get');

        // makeCompanyUser já cria settings com ai_enabled=true; apenas atualizamos
        CompanyBotSetting::where('company_id', $company->id)->update([
            'ai_chatbot_mode' => 'always',
            'ai_system_prompt' => 'Prompt salvo',
            'ai_temperature' => 1.2,
            'ai_max_response_tokens' => 2048,
        ]);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/bot');

        $response->assertOk();
        $response->assertJsonPath('settings.ai_enabled', true);
        $response->assertJsonPath('settings.ai_chatbot_mode', 'always');
        $response->assertJsonPath('settings.ai_system_prompt', 'Prompt salvo');
        $response->assertJsonPath('settings.ai_temperature', 1.2);
        $response->assertJsonPath('settings.ai_max_response_tokens', 2048);
    }

    public function test_ai_fields_omitted_from_payload_do_not_overwrite_existing(): void
    {
        [$company, $user] = $this->makeCompanyUser('preserve');

        // makeCompanyUser já cria settings com ai_enabled=true; apenas atualizamos
        CompanyBotSetting::where('company_id', $company->id)->update([
            'ai_chatbot_mode' => 'always',
            'ai_system_prompt' => 'Prompt original',
        ]);

        // Envia payload legado sem nenhum campo de IA
        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'welcome_message' => 'Mensagem nova',
        ]));

        $response->assertOk();

        // Campos de IA devem estar preservados
        $this->assertDatabaseHas('company_bot_settings', [
            'company_id' => $company->id,
            'welcome_message' => 'Mensagem nova',
            'ai_enabled' => true,
            'ai_chatbot_mode' => 'always',
            'ai_system_prompt' => 'Prompt original',
        ]);
    }

    public function test_ai_nullable_fields_can_be_cleared(): void
    {
        [$company, $user] = $this->makeCompanyUser('clear');

        CompanyBotSetting::where('company_id', $company->id)->update([
            'ai_persona' => 'Persona antiga',
            'ai_usage_limit_monthly' => 200,
        ]);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'ai_persona' => null,
            'ai_usage_limit_monthly' => null,
        ]));

        $response->assertOk();
        $response->assertJsonPath('settings.ai_persona', null);
        $response->assertJsonPath('settings.ai_usage_limit_monthly', null);

        $this->assertDatabaseHas('company_bot_settings', [
            'company_id' => $company->id,
            'ai_persona' => null,
            'ai_usage_limit_monthly' => null,
        ]);
    }

    public function test_update_with_only_meta_phone_number_id_uses_saved_token_for_validation(): void
    {
        [$company, $user] = $this->makeCompanyUser('creds-phone-only');

        $company->meta_phone_number_id = 'phone-old';
        $company->meta_access_token = 'token-saved';
        $company->save();

        $validatorMock = Mockery::mock(WhatsAppCredentialsValidatorService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->with('phone-new', 'token-saved')
            ->andReturn([
                'ok' => true,
                'display_phone_number' => '+55 11 99999-9999',
                'verified_name' => 'Empresa Teste',
                'error' => null,
            ]);
        $this->app->instance(WhatsAppCredentialsValidatorService::class, $validatorMock);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'meta_phone_number_id' => 'phone-new',
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame('phone-new', $company->fresh()->meta_phone_number_id);
    }

    public function test_update_with_same_saved_credentials_does_not_call_validator(): void
    {
        [$company, $user] = $this->makeCompanyUser('creds-same');

        $company->meta_phone_number_id = 'phone-same';
        $company->meta_access_token = 'token-same';
        $company->save();

        $validatorMock = Mockery::mock(WhatsAppCredentialsValidatorService::class);
        $validatorMock->shouldNotReceive('validate');
        $this->app->instance(WhatsAppCredentialsValidatorService::class, $validatorMock);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'meta_phone_number_id' => 'phone-same',
            'meta_access_token' => 'token-same',
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    public function test_update_returns_422_when_changed_credentials_are_invalid(): void
    {
        [$company, $user] = $this->makeCompanyUser('creds-invalid');

        $company->meta_phone_number_id = 'phone-old';
        $company->meta_access_token = 'token-old';
        $company->save();

        $validatorMock = Mockery::mock(WhatsAppCredentialsValidatorService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->with('phone-invalid', 'token-invalid')
            ->andReturn([
                'ok' => false,
                'display_phone_number' => null,
                'verified_name' => null,
                'error' => 'Token invalido ou expirado.',
            ]);
        $this->app->instance(WhatsAppCredentialsValidatorService::class, $validatorMock);

        $response = $this->actingAs($user)->putJson('/api/minha-conta/bot', $this->basePayload([
            'meta_phone_number_id' => 'phone-invalid',
            'meta_access_token' => 'token-invalid',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Credenciais do WhatsApp inválidas: Token invalido ou expirado.');
    }
}

