<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cobre a correção de enumeração de usuários no endpoint de login.
 *
 * Os 3 cenários de falha devem produzir resposta idêntica:
 *   HTTP 401 + { message: "Credenciais inválidas.", error: "invalid_credentials" }
 *
 * O motivo real é diferenciado apenas em logs internos (auth.login_failed).
 */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;


    private function makeCompany(string $name = 'Empresa Teste'): Company
    {
        return Company::create(['name' => $name]);
    }

    private function makeUser(array $overrides = []): User
    {
        $company = $this->makeCompany();

        return User::create(array_merge([
            'name'       => 'Usuário Teste',
            'email'      => 'usuario@test.local',
            'password'   => 'senha-correta-123',
            'role'       => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active'  => true,
        ], $overrides));
    }

    private function assertGenericCredentialsError(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Credenciais inválidas.');
        $response->assertJsonPath('error', 'invalid_credentials');
        $response->assertJsonPath('code', 401);
    }


    public function test_unknown_email_returns_401_with_generic_message(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'nao-existe@test.local',
            'password' => 'qualquer-senha',
        ]);

        $this->assertGenericCredentialsError($response);
    }

    public function test_unknown_email_does_not_leak_user_existence(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'nao-existe@test.local',
            'password' => 'qualquer-senha',
        ]);

        $response->assertJsonMissing(['user_inactive']);
        $response->assertJsonMissing(['Usuário inativo']);
        $response->assertJsonMissing(['inactive']);
    }


    public function test_wrong_password_returns_401_with_generic_message(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-errada',
        ]);

        $this->assertGenericCredentialsError($response);
    }

    public function test_wrong_password_response_is_identical_to_unknown_email(): void
    {
        $this->makeUser();

        $unknownResponse = $this->postJson('/api/login', [
            'email'    => 'nao-existe@test.local',
            'password' => 'qualquer',
        ]);

        $wrongPwResponse = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'errada',
        ]);

        $this->assertSame(
            $unknownResponse->status(),
            $wrongPwResponse->status(),
            'HTTP status deve ser idêntico entre email inexistente e senha errada.'
        );

        $this->assertSame(
            $unknownResponse->json('message'),
            $wrongPwResponse->json('message'),
            'Mensagem deve ser idêntica entre email inexistente e senha errada.'
        );

        $this->assertSame(
            $unknownResponse->json('error'),
            $wrongPwResponse->json('error'),
            'Error code deve ser idêntico entre email inexistente e senha errada.'
        );
    }


    public function test_inactive_user_with_correct_password_returns_401_not_403(): void
    {
        $this->makeUser(['is_active' => false]);

        $response = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $this->assertGenericCredentialsError($response);
    }

    public function test_inactive_user_response_does_not_reveal_account_status(): void
    {
        $this->makeUser(['is_active' => false]);

        $response = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $response->assertJsonMissing(['user_inactive']);
        $response->assertJsonMissing(['Usuário inativo']);
        $response->assertJsonMissing(['inactive']);
        $response->assertJsonMissing(['administrador']);
    }

    public function test_inactive_user_response_is_identical_to_wrong_password(): void
    {
        $this->makeUser(['is_active' => false]);

        $inactiveResponse = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $wrongPwResponse = $this->postJson('/api/login', [
            'email'    => 'nao-existe@test.local',
            'password' => 'qualquer',
        ]);

        $this->assertSame($inactiveResponse->status(), $wrongPwResponse->status());
        $this->assertSame($inactiveResponse->json('message'), $wrongPwResponse->json('message'));
        $this->assertSame($inactiveResponse->json('error'), $wrongPwResponse->json('error'));
    }


    public function test_inactive_user_session_is_invalidated_after_attempt(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_access_protected_route_after_failed_attempt(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $meResponse = $this->getJson('/api/me');
        $meResponse->assertStatus(401);
    }


    public function test_unknown_user_is_logged_with_correct_reason(): void
    {
        Log::spy();

        $this->postJson('/api/login', [
            'email'    => 'nao-existe@test.local',
            'password' => 'qualquer',
        ]);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->with('auth.login_failed', \Mockery::on(
                fn (array $ctx) => ($ctx['reason'] ?? '') === 'unknown_user'
            ));
    }

    public function test_wrong_password_is_logged_with_correct_reason(): void
    {
        Log::spy();

        $this->makeUser();

        $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'errada',
        ]);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->with('auth.login_failed', \Mockery::on(
                fn (array $ctx) => ($ctx['reason'] ?? '') === 'invalid_password'
            ));
    }

    public function test_inactive_user_is_logged_with_correct_reason(): void
    {
        Log::spy();

        $user = $this->makeUser(['is_active' => false]);

        $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->with('auth.login_failed', \Mockery::on(
                fn (array $ctx) => ($ctx['reason'] ?? '') === 'inactive_user'
                    && ($ctx['user_id'] ?? 0) === (int) $user->id
            ));
    }


    public function test_active_user_with_correct_credentials_still_logs_in(): void
    {
        $this->makeUser(['is_active' => true]);

        $response = $this->postJson('/api/login', [
            'email'    => 'usuario@test.local',
            'password' => 'senha-correta-123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonStructure(['user' => ['id', 'email', 'role']]);
    }
}
