<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppImageFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.app_secret', 'test-secret');

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is not installed.');
        }
    }

    public function test_webhook_image_message_is_stored_with_media_reference(): void
    {
        Storage::fake('public');

        $company = Company::create([
            'name' => 'Empresa Midia',
            'meta_phone_number_id' => 'meta-number-1',
            'meta_access_token' => 'token-empresa',
        ]);

        $imageFile = UploadedFile::fake()->image('incoming.jpg', 50, 50);
        $binary = (string) file_get_contents($imageFile->getRealPath());
        $baseApiUrl = rtrim((string) config('whatsapp.api_url'), '/');

        Http::fake([
            "{$baseApiUrl}/media-id-123" => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_media_123',
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($binary),
            ], 200),
            'https://lookaside.fbsbx.com/whatsapp_media_123' => Http::response($binary, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
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
                                    'phone_number_id' => 'meta-number-1',
                                ],
                                'contacts' => [
                                    [
                                        'wa_id' => '5511999998888',
                                        'profile' => ['name' => 'Cliente Foto'],
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.123',
                                        'from' => '5511999998888',
                                        'type' => 'image',
                                        'image' => [
                                            'id' => 'media-id-123',
                                            'caption' => 'Foto de teste',
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

        $conversation = Conversation::query()
            ->where('company_id', $company->id)
            ->where('customer_phone', '5511999998888')
            ->first();

        $this->assertNotNull($conversation);

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('content_type', 'image')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('in', $message->direction);
        $this->assertSame('user', $message->type);
        $this->assertSame('Foto de teste', $message->text);
        $this->assertSame('public', $message->media_provider);
        $this->assertNotNull($message->media_key);
        $this->assertNotNull($message->media_url);
        $this->assertSame('image/jpeg', $message->media_mime_type);
        $this->assertNotNull($message->media_size_bytes);

        Storage::disk('public')->assertExists((string) $message->media_key);
    }

    public function test_company_manual_reply_accepts_image_upload_and_stores_media_reference(): void
    {
        Storage::fake('public');

        $company = Company::create(['name' => 'Empresa Manual Midia']);
        $user = User::create([
            'name' => 'Operador Midia',
            'email' => 'midia@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'customer_phone' => '5511888880000',
            'status' => 'open',
            'assigned_type' => 'unassigned',
            'handling_mode' => 'bot',
        ]);

        $image = UploadedFile::fake()->image('outbound.png', 120, 60);

        $response = $this->actingAs($user)->post("/api/minha-conta/conversas/{$conversation->id}/responder-manual", [
            'text' => 'Segue a imagem',
            'send_outbound' => '0',
            'image' => $image,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('message.content_type', 'image');
        $response->assertJsonPath('message.direction', 'out');
        $response->assertJsonPath('message.type', 'human');

        $message = Message::query()->find((int) $response->json('message.id'));
        $this->assertNotNull($message);
        $this->assertSame('image', $message->content_type);
        $this->assertNotNull($message->media_key);
        $this->assertNotNull($message->media_url);
        $this->assertSame('Segue a imagem', $message->text);

        Storage::disk('public')->assertExists((string) $message->media_key);
    }

    public function test_admin_simulator_response_hides_media_url_for_privacy(): void
    {
        Storage::fake('public');

        $company = Company::create(['name' => 'Empresa Privacidade Midia']);
        $admin = User::create([
            'name' => 'Admin Sistema',
            'email' => 'admin-privacy@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_SYSTEM_ADMIN,
            'company_id' => null,
            'is_active' => true,
        ]);

        $image = UploadedFile::fake()->image('simulator.png', 120, 60);

        $response = $this->actingAs($admin)->post('/api/simular/mensagem', [
            'company_id' => $company->id,
            'from' => '5511777770000',
            'text' => 'Imagem simulada',
            'send_outbound' => '0',
            'image' => $image,
        ]);

        $response->assertOk();
        $response->assertJsonPath('in_message.content_type', 'image');
        $response->assertJsonPath('in_message.media_url', null);
    }
}
