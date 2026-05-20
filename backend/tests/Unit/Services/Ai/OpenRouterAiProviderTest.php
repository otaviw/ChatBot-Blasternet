<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Providers\OpenRouterAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenRouterAiProviderTest extends TestCase
{
    public function test_reply_returns_assistant_message_when_openrouter_responds_successfully(): void
    {
        config()->set('ai.providers.openrouter.api_key', 'sk-or-test');
        config()->set('ai.providers.openrouter.model', 'openrouter/free');
        config()->set('ai.providers.openrouter.base_url', 'https://openrouter.ai');
        config()->set('ai.providers.openrouter.chat_path', '/api/v1/chat/completions');
        config()->set('ai.providers.openrouter.app_name', 'ChatBot Test');
        config()->set('ai.providers.openrouter.site_url', 'https://example.test');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-123',
                'model' => 'nvidia/nemotron-test:free',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Resposta do OpenRouter',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 8,
                    'total_tokens' => 20,
                ],
            ], 200),
        ]);

        $provider = $this->app->make(OpenRouterAiProvider::class);
        $result = $provider->reply([
            ['role' => 'system', 'content' => 'contexto'],
            ['role' => 'user', 'content' => 'Ola IA'],
        ], [
            'model' => 'openrouter/free',
            'temperature' => 0.2,
            'max_response_tokens' => 120,
            'request_timeout_ms' => 8000,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Resposta do OpenRouter', $result['text'] ?? null);
        $this->assertSame('openrouter', $result['meta']['provider'] ?? null);
        $this->assertSame('nvidia/nemotron-test:free', $result['meta']['model'] ?? null);
        $this->assertSame(20, $result['meta']['usage']['total_tokens'] ?? null);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-or-test')
                && $request->hasHeader('HTTP-Referer', 'https://example.test')
                && $request->hasHeader('X-Title', 'ChatBot Test')
                && ($payload['model'] ?? null) === 'openrouter/free'
                && ($payload['temperature'] ?? null) === 0.2
                && ($payload['max_tokens'] ?? null) === 120;
        });
    }

    public function test_reply_returns_error_when_api_key_is_missing(): void
    {
        config()->set('ai.providers.openrouter.api_key', '');

        $provider = $this->app->make(OpenRouterAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste'],
        ], [
            'model' => 'openrouter/free',
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('openrouter_provider_error', $result['error'] ?? null);
        $this->assertSame('openrouter', $result['meta']['provider'] ?? null);
    }

    public function test_reply_returns_provider_error_on_http_failure(): void
    {
        config()->set('ai.providers.openrouter.api_key', 'sk-or-test');
        config()->set('ai.providers.openrouter.base_url', 'https://openrouter.ai');
        config()->set('ai.providers.openrouter.chat_path', '/api/v1/chat/completions');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => ['message' => 'No endpoints found'],
            ], 404),
        ]);

        $provider = $this->app->make(OpenRouterAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste'],
        ], [
            'model' => 'openrouter/free',
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('openrouter_provider_error', $result['error'] ?? null);
        $this->assertSame(404, $result['meta']['status'] ?? null);
        $this->assertStringContainsString('No endpoints found', $result['meta']['message'] ?? '');
    }

    public function test_reply_returns_user_friendly_timeout(): void
    {
        config()->set('ai.providers.openrouter.api_key', 'sk-or-test');
        config()->set('ai.providers.openrouter.base_url', 'https://openrouter.ai');
        config()->set('ai.providers.openrouter.chat_path', '/api/v1/chat/completions');

        Log::spy();

        Http::fake(function () {
            throw new \RuntimeException('cURL error 28: Operation timed out after 8000 milliseconds');
        });

        $provider = $this->app->make(OpenRouterAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste'],
        ], [
            'model' => 'openrouter/free',
            'request_timeout_ms' => 8000,
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('openrouter_timeout', $result['error'] ?? null);
        $this->assertSame('Assistente temporariamente indisponivel. Tente novamente em instantes.', $result['meta']['message'] ?? null);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->with('ai.provider.timeout', \Mockery::on(
                fn (array $ctx) => ($ctx['provider'] ?? '') === 'openrouter'
                    && ($ctx['timeout_seconds'] ?? 0) === 8
            ));
    }
}
