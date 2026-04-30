<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OllamaAiProvider;
use App\Services\Ai\Providers\TestAiProvider;
use Tests\Fakes\Ai\AlwaysFailAiProvider;
use Tests\Fakes\Ai\AlwaysSuccessAiProvider;
use Tests\TestCase;

class AiProviderResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.circuit_breaker.enabled', false);
    }

    public function test_resolve_returns_test_provider_when_configured(): void
    {
        config()->set('ai.provider', 'test');

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();

        $this->assertInstanceOf(TestAiProvider::class, $provider);
    }

    public function test_resolve_returns_null_provider_when_invalid_provider_is_configured(): void
    {
        config()->set('ai.provider', 'invalid_provider');

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();

        $this->assertInstanceOf(NullAiProvider::class, $provider);
    }

    public function test_resolve_uses_configured_fallback_provider_when_requested_provider_is_invalid(): void
    {
        config()->set('ai.provider', 'provider_invalido');
        config()->set('ai.fallback_provider', 'test');

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();

        $this->assertInstanceOf(TestAiProvider::class, $provider);
    }

    public function test_resolve_returns_ollama_provider_when_configured(): void
    {
        config()->set('ai.provider', 'ollama');
        config()->set('ai.ollama_fallback_provider', '');

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();

        $this->assertInstanceOf(OllamaAiProvider::class, $provider);
    }

    public function test_resolver_ignores_invalid_provider_classes_configuration_and_keeps_defaults(): void
    {
        config()->set('ai.provider_classes', [
            'broken' => 'Invalid\\Provider\\ClassName',
        ]);

        $resolver = $this->app->make(AiProviderResolver::class);

        $this->assertTrue($resolver->supports('test'));
        $this->assertTrue($resolver->supports('null'));
        $this->assertTrue($resolver->supports('ollama'));
        $this->assertTrue($resolver->supports('anthropic'));
        $this->assertFalse($resolver->supports('broken'));
    }

    public function test_test_provider_returns_prefixed_reply_from_last_user_message(): void
    {
        config()->set('ai.providers.test.reply_prefix', '[TEST]');

        $provider = $this->app->make(TestAiProvider::class);
        $result = $provider->reply([
            ['role' => 'system', 'content' => 'context'],
            ['role' => 'user', 'content' => 'Oi, tudo bem?'],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('[TEST] Oi, tudo bem?', $result['text'] ?? null);
        $this->assertSame('test', $result['meta']['provider'] ?? null);
    }

    public function test_resolver_uses_runtime_fallback_provider_when_primary_provider_fails(): void
    {
        config()->set('ai.provider', 'always_fail');
        config()->set('ai.fallback_provider', 'anthropic');
        config()->set('ai.provider_classes', [
            'always_fail' => AlwaysFailAiProvider::class,
            'anthropic' => AlwaysSuccessAiProvider::class,
            'null' => NullAiProvider::class,
        ]);

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste de fallback'],
        ], [
            'model' => 'modelo-principal',
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Fallback anthropic success', $result['text'] ?? null);
        $this->assertSame('always_fail', $result['meta']['failover']['from'] ?? null);
        $this->assertSame('anthropic', $result['meta']['failover']['to'] ?? null);
    }

    public function test_resolver_uses_ollama_dedicated_fallback_provider_when_ollama_fails(): void
    {
        config()->set('ai.provider', 'ollama');
        config()->set('ai.fallback_provider', 'null');
        config()->set('ai.ollama_fallback_provider', 'anthropic');
        config()->set('ai.provider_classes', [
            'ollama' => AlwaysFailAiProvider::class,
            'anthropic' => AlwaysSuccessAiProvider::class,
            'null' => NullAiProvider::class,
        ]);

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste de fallback ollama para anthropic'],
        ], [
            'model' => 'modelo-principal',
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Fallback anthropic success', $result['text'] ?? null);
        $this->assertSame('ollama', $result['meta']['failover']['from'] ?? null);
        $this->assertSame('anthropic', $result['meta']['failover']['to'] ?? null);
    }

    public function test_resolver_falls_back_to_global_fallback_when_ollama_dedicated_fallback_is_invalid(): void
    {
        config()->set('ai.provider', 'ollama');
        config()->set('ai.fallback_provider', 'test');
        config()->set('ai.ollama_fallback_provider', 'invalido');
        config()->set('ai.provider_classes', [
            'ollama' => AlwaysFailAiProvider::class,
            'test' => AlwaysSuccessAiProvider::class,
            'null' => NullAiProvider::class,
        ]);

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();
        $result = $provider->reply([
            ['role' => 'user', 'content' => 'Teste de fallback global'],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('ollama', $result['meta']['failover']['from'] ?? null);
        $this->assertSame('test', $result['meta']['failover']['to'] ?? null);
    }

    public function test_resolver_uses_fallback_when_primary_provider_circuit_is_open(): void
    {
        config()->set('ai.circuit_breaker.enabled', true);
        config()->set('ai.circuit_breaker.failure_threshold', 5);
        config()->set('ai.circuit_breaker.cooldown_seconds', 60);
        config()->set('ai.provider', 'ollama');
        config()->set('ai.fallback_provider', 'anthropic');
        config()->set('ai.provider_classes', [
            'ollama' => \Tests\Fakes\Ai\CountingAlwaysFailAiProvider::class,
            'anthropic' => \Tests\Fakes\Ai\AlwaysSuccessAiProvider::class,
            'null' => NullAiProvider::class,
        ]);

        \Illuminate\Support\Facades\Cache::flush();
        \Tests\Fakes\Ai\CountingAlwaysFailAiProvider::reset();

        $resolver = $this->app->make(AiProviderResolver::class);
        $provider = $resolver->resolve();

        for ($i = 0; $i < 5; $i++) {
            $provider->reply([['role' => 'user', 'content' => 'falha']]);
        }

        $result = $provider->reply([['role' => 'user', 'content' => 'deve cair no fallback com circuito aberto']]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('Fallback anthropic success', $result['text'] ?? null);
        $this->assertSame('ollama', $result['meta']['failover']['from'] ?? null);
        $this->assertSame('anthropic', $result['meta']['failover']['to'] ?? null);
        $this->assertSame('ai_provider_circuit_open', $result['meta']['failover']['primary_error'] ?? null);
        $this->assertSame(5, \Tests\Fakes\Ai\CountingAlwaysFailAiProvider::$calls);
    }
}
