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
}
