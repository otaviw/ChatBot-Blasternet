<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebhookOutboundSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_outbound_send_uses_company_phone_number_id_and_normalizes_to(): void
    {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
        config()->set('whatsapp.access_token', 'EAAATESTGLOBALTOKEN1234567890');
        config()->set('whatsapp.phone_number_id', 'ENV_FALLBACK_SHOULD_NOT_BE_USED');

        $company = Company::create([
            'name' => 'Empresa Outbound',
            'meta_phone_number_id' => '111111111111111',
        ]);

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.TEST.1'],
                ],
            ], 200),
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
                                    'phone_number_id' => '111111111111111',
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.IN.1',
                                        'from' => '+55 (54) 9 6161-912',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'oi',
                                        ],
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

        $this->assertDatabaseHas('conversations', [
            'company_id' => $company->id,
            'customer_phone' => '555496161912',
        ]);

        Http::assertSent(function (HttpRequest $request) use ($company) {
            $body = $request->data();
            $contentType = implode(',', $request->header('Content-Type'));
            $authorization = implode(',', $request->header('Authorization'));

            return $request->method() === 'POST'
                && $request->url() === "https://graph.facebook.com/v22.0/{$company->meta_phone_number_id}/messages"
                && str_contains($contentType, 'application/json')
                && str_starts_with($authorization, 'Bearer ')
                && ($body['messaging_product'] ?? null) === 'whatsapp'
                && ($body['type'] ?? null) === 'text'
                && is_string($body['to'] ?? null)
                && ($body['to'] ?? null) === '555496161912'
                && is_array($body['text'] ?? null)
                && is_string($body['text']['body'] ?? null);
        });

        Http::assertNotSent(function (HttpRequest $request) {
            return str_contains($request->url(), '/ENV_FALLBACK_SHOULD_NOT_BE_USED/messages');
        });
    }

    public function test_webhook_outbound_send_routes_by_metadata_phone_number_id_for_multiple_companies(): void
    {
        config()->set('whatsapp.api_url', 'https://graph.facebook.com/v22.0');
        config()->set('whatsapp.access_token', 'EAAATESTGLOBALTOKEN1234567890');
        config()->set('whatsapp.phone_number_id', 'ENV_FALLBACK_SHOULD_NOT_BE_USED');

        $companyA = Company::create([
            'name' => 'Empresa A',
            'meta_phone_number_id' => '222222222222222',
        ]);

        $companyB = Company::create([
            'name' => 'Empresa B',
            'meta_phone_number_id' => '333333333333333',
        ]);

        Http::fake([
            'https://graph.facebook.com/v22.0/*/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.TEST.OK'],
                ],
            ], 200),
        ]);

        $payloadA = [
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
                                'messages' => [
                                    [
                                        'id' => 'wamid.IN.A',
                                        'from' => '5511111111111',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'empresa a',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadB = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '333333333333333',
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.IN.B',
                                        'from' => '+55 (22) 9 8888-7777',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'empresa b',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payloadA)->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $payloadB)->assertOk();

        $this->assertDatabaseHas('conversations', [
            'company_id' => $companyA->id,
            'customer_phone' => '5511111111111',
        ]);

        $this->assertDatabaseHas('conversations', [
            'company_id' => $companyB->id,
            'customer_phone' => '5522988887777',
        ]);

        Http::assertSent(function (HttpRequest $request) use ($companyA) {
            $body = $request->data();

            return $request->url() === "https://graph.facebook.com/v22.0/{$companyA->meta_phone_number_id}/messages"
                && ($body['to'] ?? null) === '5511111111111';
        });

        Http::assertSent(function (HttpRequest $request) use ($companyB) {
            $body = $request->data();

            return $request->url() === "https://graph.facebook.com/v22.0/{$companyB->meta_phone_number_id}/messages"
                && ($body['to'] ?? null) === '5522988887777';
        });

        Http::assertNotSent(function (HttpRequest $request) {
            return str_contains($request->url(), '/ENV_FALLBACK_SHOULD_NOT_BE_USED/messages');
        });
    }
}
