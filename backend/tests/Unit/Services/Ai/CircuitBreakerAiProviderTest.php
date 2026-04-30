<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Providers\CircuitBreakerAiProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\Ai\CountingAlwaysFailAiProvider;
use Tests\Fakes\Ai\CountingAlwaysSuccessAiProvider;
use Tests\TestCase;

class CircuitBreakerAiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CountingAlwaysFailAiProvider::reset();
        CountingAlwaysSuccessAiProvider::reset();

        config()->set('ai.circuit_breaker.failure_threshold', 5);
        config()->set('ai.circuit_breaker.cooldown_seconds', 60);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_circuit_opens_after_five_consecutive_failures(): void
    {
        $provider = new CircuitBreakerAiProvider('ollama', new CountingAlwaysFailAiProvider());

        for ($i = 0; $i < 5; $i++) {
            $result = $provider->reply([
                ['role' => 'user', 'content' => 'teste'],
            ]);
        }

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertSame(5, CountingAlwaysFailAiProvider::$calls);
        $this->assertGreaterThan(0, (int) Cache::get('ai:provider:circuit:ollama:opened_until', 0));
    }

    public function test_circuit_blocks_calls_for_sixty_seconds_after_opening(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');
        $provider = new CircuitBreakerAiProvider('ollama', new CountingAlwaysFailAiProvider());

        for ($i = 0; $i < 5; $i++) {
            $provider->reply([
                ['role' => 'user', 'content' => 'teste'],
            ]);
        }

        $blockedResult = $provider->reply([
            ['role' => 'user', 'content' => 'nao deve chamar provider'],
        ]);

        $this->assertFalse((bool) ($blockedResult['ok'] ?? true));
        $this->assertSame('ai_provider_circuit_open', $blockedResult['error'] ?? null);
        $this->assertSame(5, CountingAlwaysFailAiProvider::$calls);
    }

    public function test_circuit_allows_calls_again_after_cooldown_window(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');
        $provider = new CircuitBreakerAiProvider('ollama', new CountingAlwaysSuccessAiProvider());

        Cache::put('ai:provider:circuit:ollama:opened_until', Carbon::now()->addSeconds(60)->timestamp, now()->addSeconds(70));

        $blockedResult = $provider->reply([
            ['role' => 'user', 'content' => 'bloqueado'],
        ]);
        $this->assertSame('ai_provider_circuit_open', $blockedResult['error'] ?? null);
        $this->assertSame(0, CountingAlwaysSuccessAiProvider::$calls);

        Carbon::setTestNow('2026-04-30 12:01:01');
        $recoveredResult = $provider->reply([
            ['role' => 'user', 'content' => 'liberado'],
        ]);

        $this->assertTrue((bool) ($recoveredResult['ok'] ?? false));
        $this->assertSame(1, CountingAlwaysSuccessAiProvider::$calls);
    }
}

