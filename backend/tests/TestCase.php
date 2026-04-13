<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Envia um POST para o webhook com assinatura HMAC válida.
     * Requer que config('whatsapp.app_secret') esteja configurado no teste.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function webhookPost(array $payload, string $secret = 'test-secret'): TestResponse
    {
        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = 'sha256=' . hash_hmac('sha256', (string) $body, $secret);

        return $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [], [], [],
            [
                'CONTENT_TYPE'             => 'application/json',
                'HTTP_X-Hub-Signature-256' => $signature,
            ],
            (string) $body
        );
    }
}
