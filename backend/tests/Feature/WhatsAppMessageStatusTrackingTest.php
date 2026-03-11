<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppMessageStatusTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_reply_persists_sent_status_and_whatsapp_message_id(): void
    {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');

        $company = Company::create([
            'name' => 'Empresa Status',
            'meta_phone_number_id' => '111111111111111',
            'meta_access_token' => 'company-token-123',
        ]);

        $user = User::create([
            'name' => 'Operador Status',
            'email' => 'status-manual@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511999990000',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.OUT.123'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson("/api/minha-conta/conversas/{$conversation->id}/responder-manual", [
            'text' => 'Mensagem com rastreio',
            'send_outbound' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('message.delivery_status', 'sent');
        $response->assertJsonPath('message.whatsapp_message_id', 'wamid.OUT.123');
        $response->assertJsonPath('was_sent', true);
        $this->assertNotNull($response->json('message.sent_at'));
    }

    public function test_webhook_statuses_update_message_tracking_fields(): void
    {
        $company = Company::create([
            'name' => 'Empresa Webhook Status',
            'meta_phone_number_id' => '222222222222222',
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511988887777',
            'status' => 'in_progress',
            'assigned_type' => 'user',
            'handling_mode' => 'human',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => 'text',
            'text' => 'Mensagem para status',
            'delivery_status' => 'pending',
            'whatsapp_message_id' => 'wamid.STATUS.1',
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
                                    'phone_number_id' => '222222222222222',
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.STATUS.1',
                                        'status' => 'sent',
                                        'timestamp' => '1710000000',
                                    ],
                                    [
                                        'id' => 'wamid.STATUS.1',
                                        'status' => 'delivered',
                                        'timestamp' => '1710000100',
                                    ],
                                    [
                                        'id' => 'wamid.STATUS.1',
                                        'status' => 'read',
                                        'timestamp' => '1710000200',
                                    ],
                                    [
                                        'id' => 'wamid.STATUS.1',
                                        'status' => 'failed',
                                        'timestamp' => '1710000300',
                                        'errors' => [
                                            [
                                                'title' => 'Delivery failed',
                                                'message' => 'Phone unavailable',
                                                'code' => 131026,
                                            ],
                                        ],
                                    ],
                                    [
                                        'id' => 'wamid.UNKNOWN',
                                        'status' => 'read',
                                        'timestamp' => '1710000400',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);
        $response->assertOk();

        $message->refresh();
        $this->assertSame('failed', $message->delivery_status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->failed_at);
        $this->assertNotNull($message->status_error);
        $this->assertStringContainsString('Delivery failed', (string) $message->status_error);
        $this->assertIsArray($message->status_meta);
    }
}
