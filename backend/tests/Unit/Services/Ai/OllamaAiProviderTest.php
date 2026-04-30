<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Providers\OllamaAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OllamaAiProviderTest extends TestCase
{
    public function test_reply_returns_assistant_message_when_ollama_responds_successfully(): void
    {
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('ai.providers.ollama.chat_path', '/api/chat');

        Http::fake([
            'http://127.0.0.1:11434/api/chat' => Http::response([
                'model' => 'gemma3:4b',
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 9,
                'eval_count' => 17,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Resposta do Ollama',
                ],
            ], 200),
        ]);

        $provider = $this->app->make(OllamaAiProvider::class);
        $result = $provider->reply([
            ['role' => 'system', 'content' => 'contexto'],
            ['role' => 'user', 'content' => 'Ola IA'],
        ], [
            'model' => 'gemma3:4b',
            'temperature' => 0.3,
            'max_response_tokens' => 180,
            'request_timeout_ms' => 30000,
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Resposta do Ollama', $result['text'] ?? null);
        $this->assertSame('ollama', $result['meta']['provider'] ?? null);
        $this->assertSame('gemma3:4b', $result['meta']['model'] ?? null);
        $this->assertSame(26, $result['meta']['usage']['total_tokens'] ?? null);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'http://127.0.0.1:11434/api/chat'
                && ($payload['model'] ?? null) === 'gemma3:4b'
                && ($payload['stream'] ?? null) === false
                && ($payload['options']['temperature'] ?? null) === 0.3
                && ($payload['options']['num_predict'] ?? null) === 180;
        });
    }

    public function test_reply_returns_error_result_when_ollama_is_unreachable(): void
    {
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('ai.providers.ollama.chat_path', '/api/chat');

        Http::fake(function () {
            throw new \RuntimeException('Connection refused');
        });

        $provider = $this->app->make(OllamaAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste'],
        ], [
            'model' => 'gemma3:4b',
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('ollama_provider_error', $result['error'] ?? null);
        $this->assertStringContainsString(
            'Ollama',
            (string) ($result['meta']['message'] ?? '')
        );
    }

    public function test_reply_returns_user_friendly_message_on_timeout(): void
    {
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('ai.providers.ollama.chat_path', '/api/chat');

        Http::fake(function () {
            throw new \RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds');
        });

        $provider = $this->app->make(OllamaAiProvider::class);
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Mensagem de teste'],
        ], [
            'model'               => 'gemma3:4b',
            'request_timeout_ms'  => 30000,
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame('ollama_timeout', $result['error'] ?? null);
        $this->assertSame(
            'Assistente temporariamente indisponível. Tente novamente em instantes.',
            $result['meta']['message'] ?? null
        );
        $this->assertSame(30, $result['meta']['timeout_seconds'] ?? null);
    }

    public function test_reply_logs_warning_on_timeout(): void
    {
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('ai.providers.ollama.chat_path', '/api/chat');

        Log::spy();

        Http::fake(function () {
            throw new \RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds');
        });

        $provider = $this->app->make(OllamaAiProvider::class);
        $provider->reply([
            ['role' => 'user', 'content' => 'Mensagem de teste'],
        ], [
            'model'              => 'gemma3:4b',
            'request_timeout_ms' => 30000,
        ]);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->with('ai.provider.timeout', \Mockery::on(
                fn (array $ctx) => ($ctx['provider'] ?? '') === 'ollama'
                    && ($ctx['timeout_seconds'] ?? 0) === 30
            ));
    }

    public function test_connection_refused_uses_generic_error_code_not_timeout(): void
    {
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('ai.providers.ollama.chat_path', '/api/chat');

        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $provider = $this->app->make(OllamaAiProvider::class);
        $result = $provider->reply([['role' => 'user', 'content' => 'a']], ['model' => 'gemma3:4b']);

        $this->assertSame('ollama_provider_error', $result['error'] ?? null);
        $this->assertStringContainsString('Ollama', $result['meta']['message'] ?? '');
        $this->assertStringNotContainsString(
            'Assistente temporariamente',
            $result['meta']['message'] ?? ''
        );
    }
}
