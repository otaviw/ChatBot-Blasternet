<?php

namespace Tests\Unit\Services\Ai;

use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\ChatbotAiIntentClassifier;
use App\Services\Ai\Providers\AiProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class ChatbotAiIntentClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new ConfigRepository([
            'ai.request_timeout_ms' => 30000,
            'ai.model' => 'test-model',
            'ai.temperature' => null,
            'ai.max_response_tokens' => null,
        ]));

        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    public function test_quero_marcar_amanha_returns_agendamento_high_confidence(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'quero marcar amanhã');

        $this->assertSame('agendamento', $result['intent']);
        $this->assertGreaterThan(0.8, $result['confidence']);
        $this->assertSame('local_heuristic_agendamento', $result['reason']);
    }

    public function test_horarios_de_amanha_a_tarde_returns_agendamento_without_provider(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify(
            $this->conversation(),
            $this->settings(),
            'oi, queria ver os horarios de amanha a tarde'
        );

        $this->assertSame('agendamento', $result['intent']);
        $this->assertGreaterThan(0.8, $result['confidence']);
        $this->assertSame('local_heuristic_agendamento', $result['reason']);
    }

    public function test_quero_falar_com_atendente_returns_falar_com_atendente(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'quero falar com atendente');

        $this->assertSame('falar_com_atendente', $result['intent']);
        $this->assertTrue($result['should_transfer_to_human']);
        $this->assertSame('local_heuristic_falar_com_atendente', $result['reason']);
    }

    public function test_preciso_falar_com_financeiro_returns_financeiro_without_provider(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'ola preciso falar com o financeiro');

        $this->assertSame('financeiro', $result['intent']);
        $this->assertGreaterThan(0.8, $result['confidence']);
        $this->assertSame('local_heuristic_financeiro', $result['reason']);
    }

    public function test_provider_is_still_used_when_local_heuristic_does_not_match(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldReceive('reply')->once()->andReturn([
            'ok' => true,
            'text' => json_encode([
                'intent' => 'duvida_geral',
                'confidence' => 0.86,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ], JSON_UNESCAPED_UNICODE),
            'error' => null,
            'meta' => ['provider' => 'test', 'model' => 'test-model'],
        ]);

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'tenho uma duvida sobre o plano');

        $this->assertSame('duvida_geral', $result['intent']);
        $this->assertSame('provider_classification', $result['reason']);
    }

    public function test_numeric_input_does_not_call_ai(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), '2');

        $this->assertSame('menu', $result['intent']);
        $this->assertSame(1.0, $result['confidence']);
    }

    public function test_active_stateful_flow_input_does_not_call_ai_provider(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldNotReceive('reply');

        $conversation = $this->conversation();
        $conversation->bot_flow = 'appointments';
        $conversation->bot_step = 'day_select';

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($conversation, $this->settings(), 'pode ser amanha a tarde?');

        $this->assertSame('menu', $result['intent']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame('active_flow_input_without_ai_provider', $result['reason']);
        $this->assertSame('appointments', $result['extracted_data']['flow'] ?? null);
        $this->assertSame('day_select', $result['extracted_data']['step'] ?? null);
    }

    public function test_ambiguous_message_returns_fallback(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldReceive('reply')->once()->andReturn([
            'ok' => true,
            'text' => 'nao eh json',
            'error' => null,
            'meta' => ['provider' => 'test'],
        ]);

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'hmm');

        $this->assertSame('fallback', $result['intent']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_provider_error_returns_safe_fallback(): void
    {
        [$resolver, $provider] = $this->resolverAndProvider();
        $provider->shouldReceive('reply')->once()->andThrow(new \RuntimeException('provider down'));

        $classifier = new ChatbotAiIntentClassifier($resolver);
        $result = $classifier->classify($this->conversation(), $this->settings(), 'preciso de ajuda');

        $this->assertSame('fallback', $result['intent']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame('provider_exception', $result['reason']);
    }

    private function resolverAndProvider(): array
    {
        $provider = Mockery::mock(AiProvider::class);
        $resolver = Mockery::mock(AiProviderResolver::class);
        $resolver->shouldReceive('defaultProviderName')->andReturn('test');
        $resolver->shouldReceive('resolveProviderName')->andReturn('test');
        $resolver->shouldReceive('supports')->andReturn(true);
        $resolver->shouldReceive('resolve')->with('test')->andReturn($provider);

        return [$resolver, $provider];
    }

    private function settings(): CompanyBotSetting
    {
        return new CompanyBotSetting([
            'company_id' => 10,
            'ai_provider' => 'test',
            'ai_model' => 'test-model',
        ]);
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;

        return $conversation;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }
}
