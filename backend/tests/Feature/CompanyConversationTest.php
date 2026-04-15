<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAgent(Company $company): User
{
    return User::create([
        'name'       => 'Agente Teste',
        'email'      => fake()->unique()->safeEmail(),
        'password'   => 'secret',
        'role'       => User::ROLE_AGENT,
        'company_id' => $company->id,
        'is_active'  => true,
    ]);
}

function makeCompanyAdmin(Company $company): User
{
    return User::create([
        'name'       => 'Admin Empresa',
        'email'      => fake()->unique()->safeEmail(),
        'password'   => 'secret',
        'role'       => User::ROLE_COMPANY_ADMIN,
        'company_id' => $company->id,
        'is_active'  => true,
    ]);
}

function makeSystemAdmin(): User
{
    return User::create([
        'name'      => 'Admin Sistema',
        'email'     => fake()->unique()->safeEmail(),
        'password'  => 'secret',
        'role'      => User::ROLE_SYSTEM_ADMIN,
        'is_active' => true,
    ]);
}

function makeOpenConversation(Company $company, string $phone = '5511999990000'): Conversation
{
    return Conversation::create([
        'company_id'     => $company->id,
        'customer_phone' => $phone,
        'status'         => ConversationStatus::OPEN,
        'handling_mode'  => ConversationHandlingMode::BOT,
        'assigned_type'  => ConversationAssignedType::BOT,
    ]);
}

// ---------------------------------------------------------------------------
// GET /api/minha-conta/conversas — listar conversas
// ---------------------------------------------------------------------------

describe('GET /minha-conta/conversas', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->getJson('/api/minha-conta/conversas')
            ->assertStatus(401);
    });

    it('retorna 403 quando o usuário é system_admin (sem empresa)', function () {
        $admin = makeSystemAdmin();

        $this->actingAs($admin)
            ->getJson('/api/minha-conta/conversas')
            ->assertStatus(403);
    });

    it('retorna lista de conversas para agente autenticado', function () {
        $company = Company::create(['name' => 'Empresa Lista']);
        $agent   = makeAgent($company);
        makeOpenConversation($company, '5511111110001');
        makeOpenConversation($company, '5511111110002');

        $response = $this->actingAs($agent)
            ->getJson('/api/minha-conta/conversas');

        $response->assertOk();
    });

    it('não expõe conversas de outra empresa', function () {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $agentA = makeAgent($companyA);
        makeOpenConversation($companyB, '5521999990001');

        $response = $this->actingAs($agentA)
            ->getJson('/api/minha-conta/conversas');

        $response->assertOk();
        // A listagem não deve conter a conversa da Empresa B
        $ids = collect($response->json('conversations') ?? $response->json('data') ?? [])
            ->pluck('company_id')
            ->unique();

        foreach ($ids as $companyId) {
            expect((int) $companyId)->toBe((int) $companyA->id);
        }
    });
});

// ---------------------------------------------------------------------------
// GET /api/minha-conta/conversas/{id} — detalhe de conversa
// ---------------------------------------------------------------------------

describe('GET /minha-conta/conversas/{id}', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->getJson('/api/minha-conta/conversas/1')
            ->assertStatus(401);
    });

    it('retorna 403 para system_admin', function () {
        $admin = makeSystemAdmin();

        $this->actingAs($admin)
            ->getJson('/api/minha-conta/conversas/1')
            ->assertStatus(403);
    });

    it('retorna 404 quando conversa pertence a outra empresa', function () {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->getJson("/api/minha-conta/conversas/{$conv->id}")
            ->assertNotFound();
    });

    it('retorna os dados da conversa para company_admin da mesma empresa', function () {
        // company_admin não tem filtro de visibilidade por área/atribuição
        $company = Company::create(['name' => 'Empresa Show']);
        $admin   = makeCompanyAdmin($company);
        $conv    = makeOpenConversation($company, '5531900000001');

        $response = $this->actingAs($admin)
            ->getJson("/api/minha-conta/conversas/{$conv->id}");

        $response->assertOk();
        expect($response->json('conversation.id'))->toBe($conv->id);
    });
});

// ---------------------------------------------------------------------------
// POST /api/minha-conta/conversas/{id}/assumir — assume conversa
// ---------------------------------------------------------------------------

describe('POST /minha-conta/conversas/{id}/assumir', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->postJson('/api/minha-conta/conversas/1/assumir')
            ->assertStatus(401);
    });

    it('muda handling_mode para human ao assumir', function () {
        $company = Company::create(['name' => 'Empresa Assumir']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $response = $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/assumir");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conversation.handling_mode', 'human');
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/assumir")
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// POST /api/minha-conta/conversas/{id}/soltar — solta conversa de volta ao bot
// ---------------------------------------------------------------------------

describe('POST /minha-conta/conversas/{id}/soltar', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->postJson('/api/minha-conta/conversas/1/soltar')
            ->assertStatus(401);
    });

    it('retorna conversa ao bot ao soltar', function () {
        $company = Company::create(['name' => 'Empresa Soltar']);
        $agent   = makeAgent($company);

        $conv = Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511000001111',
            'status'         => ConversationStatus::IN_PROGRESS,
            'handling_mode'  => ConversationHandlingMode::HUMAN,
            'assigned_type'  => ConversationAssignedType::USER,
            'assigned_id'    => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/soltar");

        $response->assertOk()->assertJsonPath('ok', true);

        $updated = $conv->fresh();
        expect($updated->handling_mode)->toBe(ConversationHandlingMode::BOT);
        expect($updated->assigned_type)->toBe(ConversationAssignedType::BOT);
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/soltar")
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// POST /api/minha-conta/conversas/{id}/encerrar — encerra conversa
// ---------------------------------------------------------------------------

describe('POST /minha-conta/conversas/{id}/encerrar', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->postJson('/api/minha-conta/conversas/1/encerrar')
            ->assertStatus(401);
    });

    it('encerra a conversa e muda status para closed', function () {
        $company = Company::create(['name' => 'Empresa Encerrar']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $response = $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/encerrar");

        $response->assertOk()->assertJsonPath('ok', true);

        expect($conv->fresh()->status)->toBe(ConversationStatus::CLOSED);
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/encerrar")
            ->assertNotFound();
    });

    it('company_admin também pode encerrar', function () {
        $company = Company::create(['name' => 'Empresa Admin Encerrar']);
        $admin   = makeCompanyAdmin($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($admin)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/encerrar")
            ->assertOk()
            ->assertJsonPath('ok', true);
    });
});

// ---------------------------------------------------------------------------
// PUT /api/minha-conta/conversas/{id}/tags — atualiza tags
// ---------------------------------------------------------------------------

describe('PUT /minha-conta/conversas/{id}/tags', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->putJson('/api/minha-conta/conversas/1/tags', ['tags' => []])
            ->assertStatus(401);
    });

    it('salva tags normalizadas em minúsculas', function () {
        $company = Company::create(['name' => 'Empresa Tags']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $response = $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/tags", [
                'tags' => ['Urgente', 'VIP', 'urgente'],  // duplicata deve ser removida
            ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $tags = $response->json('tags');
        expect($tags)->toContain('urgente')
            ->toContain('vip')
            ->toHaveCount(2);  // duplicatas removidas
    });

    it('aceita array vazio para remover todas as tags', function () {
        $company = Company::create(['name' => 'Empresa Tags Clear']);
        $agent   = makeAgent($company);

        $conv = Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511222220001',
            'status'         => ConversationStatus::OPEN,
            'tags'           => ['antigo'],
        ]);

        $response = $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/tags", ['tags' => []]);

        $response->assertOk();
        expect($conv->fresh()->tags)->toBeEmpty();
    });

    it('retorna 422 quando tags não é array', function () {
        $company = Company::create(['name' => 'Empresa Tags Inválido']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/tags", ['tags' => 'nao-e-array'])
            ->assertUnprocessable();
    });

    it('retorna 422 quando tag individual excede 50 caracteres', function () {
        $company = Company::create(['name' => 'Empresa Tags Longa']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/tags", [
                'tags' => [str_repeat('x', 51)],
            ])
            ->assertUnprocessable();
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/tags", ['tags' => ['vip']])
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// PUT /api/minha-conta/conversas/{id}/contato — atualiza nome do contato
// ---------------------------------------------------------------------------

describe('PUT /minha-conta/conversas/{id}/contato', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->putJson('/api/minha-conta/conversas/1/contato', ['customer_name' => 'Fulano'])
            ->assertStatus(401);
    });

    it('atualiza o nome do cliente', function () {
        $company = Company::create(['name' => 'Empresa Contato']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $response = $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/contato", [
                'customer_name' => 'Maria Souza',
            ]);

        $response->assertOk()->assertJsonPath('ok', true);

        expect($conv->fresh()->customer_name)->toBe('Maria Souza');
    });

    it('aceita customer_name nulo para limpar o nome', function () {
        $company = Company::create(['name' => 'Empresa Contato Null']);
        $agent   = makeAgent($company);

        $conv = Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511333330001',
            'customer_name'  => 'Nome Antigo',
            'status'         => ConversationStatus::OPEN,
        ]);

        $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/contato", ['customer_name' => null])
            ->assertOk();

        expect($conv->fresh()->customer_name)->toBeNull();
    });

    it('retorna 422 quando customer_name excede 160 caracteres', function () {
        $company = Company::create(['name' => 'Empresa Nome Longo']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($agent)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/contato", [
                'customer_name' => str_repeat('a', 161),
            ])
            ->assertUnprocessable();
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->putJson("/api/minha-conta/conversas/{$conv->id}/contato", ['customer_name' => 'X'])
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// POST /api/minha-conta/conversas/{id}/responder-manual — resposta manual
// ---------------------------------------------------------------------------

describe('POST /minha-conta/conversas/{id}/responder-manual', function () {
    it('retorna 401 para requisição não autenticada (middleware auth)', function () {
        $this->postJson('/api/minha-conta/conversas/1/responder-manual', ['text' => 'oi'])
            ->assertStatus(401);
    });

    it('cria mensagem de saída sem envio externo (send_outbound=false)', function () {
        $company = Company::create(['name' => 'Empresa Reply']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $response = $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/responder-manual", [
                'text'          => 'Olá, tudo bem!',
                'send_outbound' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.direction', 'out')
            ->assertJsonPath('message.meta.source', 'manual')
            ->assertJsonPath('was_sent', false);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'direction'       => 'out',
            'text'            => 'Olá, tudo bem!',
            'delivery_status' => MessageDeliveryStatus::PENDING,
        ]);
    });

    it('retorna 422 quando texto e arquivo estão ambos ausentes', function () {
        $company = Company::create(['name' => 'Empresa Reply Vazio']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/responder-manual", [
                'text'          => '',
                'send_outbound' => false,
            ])
            ->assertUnprocessable();
    });

    it('retorna 422 quando texto excede 2000 caracteres', function () {
        $company = Company::create(['name' => 'Empresa Reply Longo']);
        $agent   = makeAgent($company);
        $conv    = makeOpenConversation($company);

        $this->actingAs($agent)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/responder-manual", [
                'text'          => str_repeat('a', 2001),
                'send_outbound' => false,
            ])
            ->assertUnprocessable();
    });

    it('retorna 409 quando conversa está assumida por outro usuário', function () {
        $company     = Company::create(['name' => 'Empresa Conflito']);
        $agentA      = makeAgent($company);
        $agentB_data = [
            'name'       => 'Agente B',
            'email'      => fake()->unique()->safeEmail(),
            'password'   => 'secret',
            'role'       => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active'  => true,
        ];
        $agentB = User::create($agentB_data);

        $conv = Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511444440001',
            'status'         => ConversationStatus::IN_PROGRESS,
            'handling_mode'  => ConversationHandlingMode::HUMAN,
            'assigned_type'  => ConversationAssignedType::USER,
            'assigned_id'    => $agentB->id,
        ]);

        $this->actingAs($agentA)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/responder-manual", [
                'text'          => 'Tentativa de resposta',
                'send_outbound' => false,
            ])
            ->assertStatus(409);
    });

    it('retorna 404 quando conversa é de outra empresa', function () {
        $companyA = Company::create(['name' => 'A']);
        $companyB = Company::create(['name' => 'B']);
        $agentA   = makeAgent($companyA);
        $conv     = makeOpenConversation($companyB);

        $this->actingAs($agentA)
            ->postJson("/api/minha-conta/conversas/{$conv->id}/responder-manual", [
                'text'          => 'oi',
                'send_outbound' => false,
            ])
            ->assertNotFound();
    });
});
