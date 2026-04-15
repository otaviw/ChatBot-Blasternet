<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use App\Mail\ResetPasswordMail;

// ---------------------------------------------------------------------------
// POST /api/forgot-password
// ---------------------------------------------------------------------------

describe('POST /forgot-password — sem enumeração de usuários', function () {
    it('retorna 200 e mesma mensagem quando e-mail está cadastrado', function () {
        Mail::fake();

        User::create([
            'name'       => 'Usuário Teste',
            'email'      => 'existe@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'existe@exemplo.com',
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'message' => 'Se o email estiver cadastrado, você receberá as instruções em breve.',
        ]);

        Mail::assertSent(ResetPasswordMail::class);
    });

    it('retorna 200 e mesma mensagem quando e-mail NÃO está cadastrado', function () {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'naoexiste@exemplo.com',
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'message' => 'Se o email estiver cadastrado, você receberá as instruções em breve.',
        ]);

        Mail::assertNotSent(ResetPasswordMail::class);
    });

    it('status HTTP é idêntico para e-mail existente e inexistente', function () {
        Mail::fake();

        User::create([
            'name'       => 'Usuário Existe',
            'email'      => 'existe2@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $statusExiste = $this->postJson('/api/forgot-password', [
            'email' => 'existe2@exemplo.com',
        ])->status();

        $statusInexiste = $this->postJson('/api/forgot-password', [
            'email' => 'fantasma@exemplo.com',
        ])->status();

        expect($statusExiste)->toBe($statusInexiste)->toBe(200);
    });

    it('corpo da resposta é idêntico para e-mail existente e inexistente', function () {
        Mail::fake();

        User::create([
            'name'       => 'Usuário Comparação',
            'email'      => 'comparacao@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $bodyExiste = $this->postJson('/api/forgot-password', [
            'email' => 'comparacao@exemplo.com',
        ])->json();

        $bodyInexiste = $this->postJson('/api/forgot-password', [
            'email' => 'nuncacadastrado@exemplo.com',
        ])->json();

        expect($bodyExiste)->toBe($bodyInexiste);
    });

    it('não envia e-mail quando usuário está inativo', function () {
        Mail::fake();

        User::create([
            'name'       => 'Inativo',
            'email'      => 'inativo@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => false,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'inativo@exemplo.com',
        ])->assertOk();

        Mail::assertNotSent(ResetPasswordMail::class);
    });
});

// ---------------------------------------------------------------------------
// POST /api/reset-password
// ---------------------------------------------------------------------------

describe('POST /reset-password — sem enumeração de usuários', function () {
    it('retorna 200 quando token e e-mail são válidos e senha é redefinida', function () {
        $user = User::create([
            'name'       => 'Reset Ok',
            'email'      => 'reset-ok@exemplo.com',
            'password'   => bcrypt('senhaantiga'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'reset-ok@exemplo.com',
            'token'                 => $token,
            'password'              => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['message' => 'Senha redefinida com sucesso.']);
    });

    it('retorna 422 com mensagem genérica quando token é inválido', function () {
        User::create([
            'name'       => 'Token Inválido',
            'email'      => 'token-inválido@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'token-inválido@exemplo.com',
            'token'                 => 'token-errado-qualquer',
            'password'              => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Link inválido ou expirado. Solicite um novo.',
        ]);
    });

    it('retorna 422 com mensagem genérica quando e-mail não existe', function () {
        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'naoexiste@exemplo.com',
            'token'                 => 'qualquer-token',
            'password'              => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Link inválido ou expirado. Solicite um novo.',
        ]);
    });

    it('status e mensagem são idênticos para token inválido vs e-mail inexistente', function () {
        User::create([
            'name'       => 'Comparação Reset',
            'email'      => 'comparacao-reset@exemplo.com',
            'password'   => bcrypt('senha123'),
            'is_active'  => true,
            'role'       => 'company',
            'company_id' => null,
        ]);

        $respostaTokenInvalido = $this->postJson('/api/reset-password', [
            'email'                 => 'comparacao-reset@exemplo.com',
            'token'                 => 'token-errado',
            'password'              => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        $respostaEmailInexistente = $this->postJson('/api/reset-password', [
            'email'                 => 'fantasma@exemplo.com',
            'token'                 => 'token-errado',
            'password'              => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        expect($respostaTokenInvalido->status())->toBe($respostaEmailInexistente->status());
        expect($respostaTokenInvalido->json('message'))->toBe($respostaEmailInexistente->json('message'));
    });
});
