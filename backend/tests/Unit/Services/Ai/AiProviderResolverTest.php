<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\TestAiProvider;
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
}
