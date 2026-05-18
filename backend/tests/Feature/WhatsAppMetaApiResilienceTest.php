<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\WhatsApp\MediaMessageHandler;
use App\Services\WhatsApp\TemplateMessageHandler;
use App\Services\WhatsApp\TextMessageHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppMetaApiResilienceTest extends TestCase
{
    private function makeCompany(): Company
    {
        return new Company([
            'id' => 10,
            'name' => 'Empresa Resilience',
            'meta_phone_number_id' => '1234567890',
            'meta_access_token' => 'EAAB_VALID_TOKEN_EXAMPLE',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
    }

    public function test_text_send_maps_auth_error(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => [
                    'message' => 'Erro da Meta',
                    'code' => 190,
                ],
            ], 401),
        ]);

        $result = $handler->send($company, '5511999999999', 'teste');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_TOKEN_INVALID', $result['error']['code'] ?? null);
        $this->assertFalse((bool) ($result['error']['retryable'] ?? true));
    }

    public function test_text_send_maps_rate_limit_error(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => [
                    'message' => 'Rate limit',
                    'code' => 130429,
                ],
            ], 429),
        ]);

        $result = $handler->send($company, '5511999999999', 'teste');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_RATE_LIMIT', $result['error']['code'] ?? null);
        $this->assertTrue((bool) ($result['error']['retryable'] ?? false));
    }

    public function test_text_send_maps_server_error(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => [
                    'message' => 'Server error',
                    'code' => 2,
                ],
            ], 503),
        ]);

        $result = $handler->send($company, '5511999999999', 'teste');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_SERVER_ERROR', $result['error']['code'] ?? null);
        $this->assertTrue((bool) ($result['error']['retryable'] ?? false));
    }

    public function test_text_send_maps_timeout_as_retryable_connection_error(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake(function (): never {
            throw new ConnectionException('timeout');
        });

        $result = $handler->send($company, '5511999999999', 'teste');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_CONNECTION_ERROR', $result['error']['code'] ?? null);
        $this->assertTrue((bool) ($result['error']['retryable'] ?? false));
    }

    public function test_text_send_maps_permission_denied_error(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => [
                    'message' => 'Permission denied',
                    'code' => 200,
                ],
            ], 403),
        ]);

        $result = $handler->send($company, '5511999999999', 'teste');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_PERMISSION_DENIED', $result['error']['code'] ?? null);
        $this->assertFalse((bool) ($result['error']['retryable'] ?? true));
    }

    public function test_template_send_maps_template_rejected_error(): void
    {
        $handler = new TemplateMessageHandler();
        $company = $this->makeCompany();
        $company->meta_waba_id = 'waba-1';

        Http::fake([
            'https://graph.facebook.com/v22.0/*/message_templates*' => Http::response(['data' => []], 200),
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => [
                    'message' => 'Template rejected',
                    'code' => 132015,
                ],
            ], 400),
        ]);

        $result = $handler->send($company, '5511999999999', 'template_invalido', ['A']);

        $this->assertFalse($result['ok']);
        $this->assertSame('META_API_TEMPLATE_REJECTED', $result['error']['code'] ?? null);
        $this->assertFalse((bool) ($result['error']['retryable'] ?? true));
    }

    public function test_media_send_maps_media_and_recipient_and_unexpected_errors(): void
    {
        $handler = new MediaMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::sequence()
                ->push([
                    'error' => ['message' => 'Media error', 'code' => 131052],
                ], 400)
                ->push([
                    'error' => ['message' => 'Recipient not allowed', 'code' => 131026],
                ], 400)
                ->push('unexpected-body', 418, ['Content-Type' => 'text/plain']),
        ]);

        $media = $handler->sendMedia($company, '5511999999999', 'MEDIA_ID_1', 'image');
        $recipient = $handler->sendMedia($company, '5511999999999', 'MEDIA_ID_2', 'image');
        $unexpected = $handler->sendMedia($company, '5511999999999', 'MEDIA_ID_3', 'image');

        $this->assertFalse($media['ok']);
        $this->assertSame('META_API_MEDIA_FAILED', $media['error']['code'] ?? null);

        $this->assertFalse($recipient['ok']);
        $this->assertSame('META_API_RECIPIENT_NOT_ALLOWED', $recipient['error']['code'] ?? null);

        $this->assertFalse($unexpected['ok']);
        $this->assertSame('META_API_UNEXPECTED_RESPONSE', $unexpected['error']['code'] ?? null);
        Http::assertSentCount(3);
    }

    public function test_error_flow_does_not_retry_and_prevents_duplicate_send(): void
    {
        $handler = new TextMessageHandler();
        $company = $this->makeCompany();

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'error' => ['message' => 'rate', 'code' => 130429],
            ], 429),
        ]);

        $handler->send($company, '5511999999999', 'teste');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    }
}
