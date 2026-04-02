<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Support\MessageDeliveryStatus;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeWebhookPayload(string $phoneNumberId, array $messages = [], array $statuses = [], array $contacts = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => array_filter([
                            'metadata'  => ['phone_number_id' => $phoneNumberId],
                            'messages'  => $messages ?: null,
                            'statuses'  => $statuses ?: null,
                            'contacts'  => $contacts ?: null,
                        ]),
                    ],
                ],
            ],
        ],
    ];
}

function makeCompany(string $phoneNumberId = '111000111000111'): Company
{
    return Company::create([
        'name'                 => "Empresa {$phoneNumberId}",
        'meta_phone_number_id' => $phoneNumberId,
    ]);
}

// ---------------------------------------------------------------------------
// GET /api/webhooks/whatsapp  — verificação do webhook pelo Meta
// ---------------------------------------------------------------------------

describe('Webhook verify (GET)', function () {
    it('retorna o challenge quando mode=subscribe e token correto', function () {
        config()->set('whatsapp.verify_token', 'token-secreto');

        $response = $this->getJson('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=token-secreto&hub.challenge=ABC123');

        $response->assertOk();
        expect($response->getContent())->toBe('ABC123');
    });

    it('retorna 403 quando token errado', function () {
        config()->set('whatsapp.verify_token', 'token-secreto');

        $response = $this->getJson('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=ERRADO&hub.challenge=ABC123');

        $response->assertForbidden();
    });

    it('retorna 403 quando mode diferente de subscribe', function () {
        config()->set('whatsapp.verify_token', 'token-secreto');

        $response = $this->getJson('/api/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=token-secreto&hub.challenge=ABC123');

        $response->assertForbidden();
    });

    it('aceita parametros com underline (hub_mode / hub_verify_token)', function () {
        config()->set('whatsapp.verify_token', 'meu-token');

        $response = $this->call('GET', '/api/webhooks/whatsapp', [
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'meu-token',
            'hub_challenge'    => 'CHALLENGE_XYZ',
        ]);

        $response->assertOk();
        expect($response->getContent())->toBe('CHALLENGE_XYZ');
    });
});

// ---------------------------------------------------------------------------
// POST /api/webhooks/whatsapp  — recebimento de eventos do Meta
// ---------------------------------------------------------------------------

describe('Webhook handle (POST) — object inválido', function () {
    it('retorna 200 sem processar quando object não é whatsapp_business_account', function () {
        $payload = ['object' => 'page', 'entry' => []];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect(Conversation::count())->toBe(0);
    });
});

describe('Webhook handle (POST) — phone_number_id desconhecido', function () {
    it('ignora silenciosamente e retorna 200 quando nenhuma empresa corresponde ao phone_number_id', function () {
        Http::fake();

        $payload = makeWebhookPayload('NUMERO_INEXISTENTE', [
            ['id' => 'wamid.1', 'from' => '5511999999999', 'type' => 'text', 'text' => ['body' => 'oi']],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect(Conversation::count())->toBe(0);
        Http::assertNothingSent();
    });
});

describe('Webhook handle (POST) — mensagens de texto', function () {
    it('cria conversa e mensagem quando recebe texto de número conhecido', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $company = makeCompany('222000222000222');

        $payload = makeWebhookPayload('222000222000222', [
            [
                'id'   => 'wamid.IN.1',
                'from' => '5511988887777',
                'type' => 'text',
                'text' => ['body' => 'Olá, preciso de ajuda'],
            ],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('conversations', [
            'company_id'     => $company->id,
            'customer_phone' => '5511988887777',
        ]);

        $conversation = Conversation::where('company_id', $company->id)->first();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'text'            => 'Olá, preciso de ajuda',
        ]);
    });

    it('normaliza número com formatação (parênteses, espaços, traços) ao criar conversa', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $company = makeCompany('333000333000333');

        $payload = makeWebhookPayload('333000333000333', [
            [
                'id'   => 'wamid.IN.2',
                'from' => '+55 (11) 9 8888-7777',
                'type' => 'text',
                'text' => ['body' => 'mensagem formatada'],
            ],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('conversations', [
            'company_id'     => $company->id,
            'customer_phone' => '5511988887777',
        ]);
    });

    it('usa nome do contato quando fornecido no campo contacts[]', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $company = makeCompany('444000444000444');

        $payload = makeWebhookPayload(
            '444000444000444',
            [
                ['id' => 'wamid.IN.3', 'from' => '5521977776666', 'type' => 'text', 'text' => ['body' => 'oi']],
            ],
            [],
            [
                ['wa_id' => '5521977776666', 'profile' => ['name' => 'João Silva']],
            ]
        );

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('conversations', [
            'company_id'     => $company->id,
            'customer_phone' => '5521977776666',
            'customer_name'  => 'João Silva',
        ]);
    });

    it('ignora mensagem de texto com body vazio', function () {
        Http::fake();
        $company = makeCompany('555000555000555');

        $payload = makeWebhookPayload('555000555000555', [
            ['id' => 'wamid.IN.4', 'from' => '5511111111111', 'type' => 'text', 'text' => ['body' => '   ']],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect(Conversation::where('company_id', $company->id)->count())->toBe(0);
    });

    it('ignora entrada sem campo from', function () {
        Http::fake();
        $company = makeCompany('666000666000666');

        $payload = makeWebhookPayload('666000666000666', [
            ['id' => 'wamid.IN.5', 'from' => '', 'type' => 'text', 'text' => ['body' => 'teste']],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect(Conversation::where('company_id', $company->id)->count())->toBe(0);
    });
});

describe('Webhook handle (POST) — status de entrega', function () {
    it('atualiza delivery_status para sent quando recebe status=sent', function () {
        $company = makeCompany('777000777000777');

        $conversation = Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511222223333',
            'status'         => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Mensagem enviada',
            'whatsapp_message_id' => 'wamid.STATUS.1',
            'delivery_status'    => MessageDeliveryStatus::PENDING,
        ]);

        $payload = makeWebhookPayload('777000777000777', [], [
            ['id' => 'wamid.STATUS.1', 'status' => 'sent', 'timestamp' => time()],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect($message->fresh()->delivery_status)->toBe(MessageDeliveryStatus::SENT);
    });

    it('atualiza delivery_status para delivered', function () {
        $company = makeCompany('888000888000888');

        $conversation = Conversation::create([
            'company_id' => $company->id, 'customer_phone' => '5511444445555', 'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Entregue',
            'whatsapp_message_id' => 'wamid.STATUS.2',
            'delivery_status'    => MessageDeliveryStatus::SENT,
        ]);

        $payload = makeWebhookPayload('888000888000888', [], [
            ['id' => 'wamid.STATUS.2', 'status' => 'delivered', 'timestamp' => time()],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect($message->fresh()->delivery_status)->toBe(MessageDeliveryStatus::DELIVERED);
    });

    it('atualiza delivery_status para read', function () {
        $company = makeCompany('900000900000900');

        $conversation = Conversation::create([
            'company_id' => $company->id, 'customer_phone' => '5511666667777', 'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Lido',
            'whatsapp_message_id' => 'wamid.STATUS.3',
            'delivery_status'    => MessageDeliveryStatus::DELIVERED,
        ]);

        $payload = makeWebhookPayload('900000900000900', [], [
            ['id' => 'wamid.STATUS.3', 'status' => 'read', 'timestamp' => time()],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect($message->fresh()->delivery_status)->toBe(MessageDeliveryStatus::READ);
    });

    it('atualiza delivery_status para failed e persiste status_error', function () {
        $company = makeCompany('101000101000101');

        $conversation = Conversation::create([
            'company_id' => $company->id, 'customer_phone' => '5511888889999', 'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Falha',
            'whatsapp_message_id' => 'wamid.STATUS.4',
            'delivery_status'    => MessageDeliveryStatus::SENT,
        ]);

        $payload = makeWebhookPayload('101000101000101', [], [
            [
                'id'        => 'wamid.STATUS.4',
                'status'    => 'failed',
                'timestamp' => time(),
                'errors'    => [['title' => 'Number does not exist', 'code' => '131026']],
            ],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $updated = $message->fresh();
        expect($updated->delivery_status)->toBe(MessageDeliveryStatus::FAILED);
        expect($updated->status_error)->toContain('131026');
    });

    it('ignora status com wamid desconhecido sem lançar erro', function () {
        $company = makeCompany('102000102000102');

        $payload = makeWebhookPayload('102000102000102', [], [
            ['id' => 'wamid.INEXISTENTE', 'status' => 'delivered', 'timestamp' => time()],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
    });
});

describe('Webhook handle (POST) — reações', function () {
    it('cria reação quando recebe evento de reaction com emoji', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $company = makeCompany('103000103000103');

        $conversation = Conversation::create([
            'company_id' => $company->id, 'customer_phone' => '5511100001111', 'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Vai reagir',
            'whatsapp_message_id' => 'wamid.REACT.1',
            'delivery_status'    => MessageDeliveryStatus::READ,
        ]);

        $payload = makeWebhookPayload('103000103000103', [
            [
                'id'       => 'wamid.REACT.EVT.1',
                'from'     => '5511100001111',
                'type'     => 'reaction',
                'reaction' => ['message_id' => 'wamid.REACT.1', 'emoji' => '👍'],
            ],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        $this->assertDatabaseHas('message_reactions', [
            'message_id'    => $message->id,
            'reactor_phone' => '5511100001111',
            'emoji'         => '👍',
        ]);
    });

    it('remove reação quando recebe evento de reaction com emoji vazio', function () {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $company = makeCompany('104000104000104');

        $conversation = Conversation::create([
            'company_id' => $company->id, 'customer_phone' => '5511200002222', 'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id'    => $conversation->id,
            'direction'          => 'out',
            'type'               => 'human',
            'content_type'       => 'text',
            'text'               => 'Com reação',
            'whatsapp_message_id' => 'wamid.REACT.2',
            'delivery_status'    => MessageDeliveryStatus::READ,
        ]);

        MessageReaction::create([
            'message_id'          => $message->id,
            'reactor_phone'       => '5511200002222',
            'whatsapp_message_id' => 'wamid.REACT.2',
            'emoji'               => '❤️',
        ]);

        $payload = makeWebhookPayload('104000104000104', [
            [
                'id'       => 'wamid.REACT.EVT.2',
                'from'     => '5511200002222',
                'type'     => 'reaction',
                'reaction' => ['message_id' => 'wamid.REACT.2', 'emoji' => ''],
            ],
        ]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        expect(MessageReaction::where('message_id', $message->id)->count())->toBe(0);
    });
});
