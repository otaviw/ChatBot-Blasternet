<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Providers\AnthropicAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicAiProviderTest extends TestCase
{
    public function test_reply_returns_text_when_anthropic_responds_successfully(): void
    {
        config()->set('ai.providers.anthropic.api_key', 'test-anthropic-key');
        config()->set('ai.providers.anthropic.model', 'claude-3-5-sonnet-latest');
        config()->set('ai.providers.anthropic.base_url', 'https://api.anthropic.com');
        config()->set('ai.providers.anthropic.messages_path', '/v1/messages');

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Resposta da Claude'],
                ],
                'usage' => [
                    'input_tokens' => 12,
                    'output_tokens' => 25,
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = $this->app->make(AnthropicAiProvider::class);
        $result = $provider->reply([
            ['role' => 'system', 'content' => 'Seja objetivo'],
            ['role' => 'user', 'content' => 'Resuma a conversa'],
        ], [
            'temperature' => 0.2,
            'max_response_tokens' => 300,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Resposta da Claude', $result['text'] ?? null);
        $this->assertSame('anthropic', $result['meta']['provider'] ?? null);
        $this->assertSame('claude-3-5-sonnet-latest', $result['meta']['model'] ?? null);
        $this->assertSame(37, $result['meta']['usage']['total_tokens'] ?? null);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-anthropic-key')
                && ($payload['model'] ?? null) === 'claude-3-5-sonnet-latest'
                && ($payload['max_tokens'] ?? null) === 300
                && ($payload['temperature'] ?? null) === 0.2
                && ($payload['system'] ?? null) === 'Seja objetivo';
        });
    }

    public function test_reply_returns_error_when_api_key_is_missing(): void
    {
        config()->set('ai.providers.anthropic.api_key', '');
        config()->set('ai.providers.anthropic.model', 'claude-3-5-sonnet-latest');

        $provider = $this->app->make(AnthropicAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste'],
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('anthropic_provider_error', $result['error'] ?? null);
        $this->assertStringContainsString('Chave da Anthropic', (string) ($result['meta']['message'] ?? ''));
    }
}
