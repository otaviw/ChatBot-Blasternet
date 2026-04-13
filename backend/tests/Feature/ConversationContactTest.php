<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.app_secret', 'test-secret');
    }

    public function test_company_user_can_update_contact_name_for_own_conversation(): void
    {
        $company = Company::create(['name' => 'Empresa Contato']);
        $user = User::create([
            'name' => 'Operador',
            'email' => 'operador-contato@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999999999',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $response = $this->actingAs($user)->putJson("/api/minha-conta/conversas/{$conversation->id}/contato", [
            'customer_name' => 'Maria Oliveira',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('conversation.customer_name', 'Maria Oliveira');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'customer_name' => 'Maria Oliveira',
        ]);
    }

    public function test_admin_can_update_contact_name_in_privacy_mode(): void
    {
        $company = Company::create(['name' => 'Empresa Admin Contato']);
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin-contato@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511888888888',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/admin/conversas/{$conversation->id}/contato", [
            'customer_name' => 'Contato Editado',
        ]);

        $response->assertOk();
        $response->assertJsonPath('privacy_mode', 'blind_default');
        $response->assertJsonPath('conversation.customer_name', 'Contato Editado');
        $response->assertJsonPath('conversation.customer_phone_masked', '*********8888');
    }

    public function test_webhook_saves_contact_name_from_whatsapp_contacts_payload(): void
    {
        $company = Company::create([
            'name' => 'Empresa Webhook',
            'meta_phone_number_id' => '1234567890',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '1234567890',
                                ],
                                'contacts' => [
                                    [
                                        'wa_id' => '5511777777777',
                                        'profile' => [
                                            'name' => 'Joao da Silva',
                                        ],
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.TESTE.1',
                                        'from' => '5511777777777',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Oi',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->webhookPost($payload);
        $response->assertOk();

        $this->assertDatabaseHas('conversations', [
            'company_id' => $company->id,
            'customer_phone' => '5511777777777',
            'customer_name' => 'Joao da Silva',
        ]);
    }
}
