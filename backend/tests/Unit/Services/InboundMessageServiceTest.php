<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiMetricsService;
use App\Services\Ai\AiSafetyPipelineService;
use App\Services\Ai\ChatbotAiDecisionLoggerService;
use App\Services\Ai\ChatbotAiDecisionService;
use App\Services\Ai\ChatbotAiGuardService;
use App\Services\Ai\ChatbotAiIntentClassifier;
use App\Services\Ai\ChatbotAiPolicyService;
use App\Services\Ai\ConversationAiSuggestionService;
use App\Services\Bot\StatefulBotService;
use App\Services\BotReplyService;
use App\Services\ConversationBootstrapService;
use App\Services\ConversationStateService;
use App\Services\InboundMessageService;
use App\Services\MessageDeliveryStatusService;
use App\Services\MessageMediaStorageService;
use App\Services\ProductMetricsService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class InboundMessageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new ConfigRepository([
            'ai.provider' => 'test',
            'ai.model' => 'test-model',
        ]));
        $app->instance('log', new class
        {
            public function warning(string $message, array $context = []): void
            {
                unset($message, $context);
            }
        });

        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    public function test_generate_chatbot_ai_reply_falls_back_to_null_on_error(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion
            ->shouldReceive('generateSuggestion')
            ->once()
            ->andThrow(new \RuntimeException('provider down'));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $this->makeMock(ChatbotAiIntentClassifier::class),
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $this->makeMock(ChatbotAiDecisionLoggerService::class),
        );

        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
        ]));

        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;

        $method = new \ReflectionMethod($service, 'generateChatbotAiReply');
        $method->setAccessible(true);
        $result = $method->invoke($service, $company, $conversation);

        $this->assertNull($result);
    }

    public function test_sandbox_off_keeps_legacy_reply(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier->shouldNotReceive('classify');

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger->shouldNotReceive('logDecision');

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(shadowEnabled: false, sandboxEnabled: false),
            gateResult: ['allowed' => true]
        );

        $this->assertSame('Resposta legado', $reply);
    }

    public function test_shadow_mode_registers_decision_and_does_not_change_reply(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'duvida_geral',
                'confidence' => 0.83,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return ($payload['mode'] ?? null) === 'shadow'
                    && ($payload['action'] ?? null) === 'suggest_reply'
                    && ($payload['intent'] ?? null) === 'duvida_geral';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta real do fluxo legado',
            company: $this->makeCompany(shadowEnabled: true, sandboxEnabled: false),
            gateResult: ['allowed' => true]
        );

        $this->assertSame('Resposta real do fluxo legado', $reply);
    }

    public function test_sandbox_on_with_test_number_can_override_reply(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion
            ->shouldReceive('generateSuggestion')
            ->once()
            ->andReturn([
                'suggestion' => 'Resposta assistida da IA',
                'confidence_score' => 0.88,
                'used_rag' => false,
                'rag_chunks' => [],
            ]);

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'duvida_geral',
                'confidence' => 0.91,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $comparison = is_array($payload['gate_result']['reply_comparison'] ?? null)
                    ? $payload['gate_result']['reply_comparison']
                    : [];

                return ($payload['mode'] ?? null) === 'sandbox'
                    && ($payload['action'] ?? null) === 'suggest_reply'
                    && ($comparison['ai_applied'] ?? null) === true
                    && ($comparison['legacy_reply'] ?? null) === 'Resposta legado'
                    && ($comparison['final_reply'] ?? null) === 'Resposta assistida da IA';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(
                shadowEnabled: false,
                sandboxEnabled: true,
                testNumbers: ['+55 (11) 99999-1111']
            ),
            gateResult: ['allowed' => true],
            normalizedFrom: '5511999991111'
        );

        $this->assertSame('Resposta assistida da IA', $reply);
    }

    public function test_sandbox_on_with_number_not_allowed_keeps_legacy(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier->shouldNotReceive('classify');

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger->shouldNotReceive('logDecision');

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(
                shadowEnabled: false,
                sandboxEnabled: true,
                testNumbers: ['5511888887777']
            ),
            gateResult: ['allowed' => true],
            normalizedFrom: '5511999991111'
        );

        $this->assertSame('Resposta legado', $reply);
    }

    public function test_ai_error_in_sandbox_falls_back_to_legacy(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion
            ->shouldReceive('generateSuggestion')
            ->once()
            ->andThrow(new \RuntimeException('provider unavailable'));

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'duvida_geral',
                'confidence' => 0.92,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return ($payload['mode'] ?? null) === 'sandbox'
                    && ($payload['action'] ?? null) === 'fallback_legacy'
                    && ($payload['error'] ?? null) === 'ai_reply_unavailable';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(
                shadowEnabled: false,
                sandboxEnabled: true,
                testNumbers: ['5511999991111']
            ),
            gateResult: ['allowed' => true],
            normalizedFrom: '5511999991111'
        );

        $this->assertSame('Resposta legado', $reply);
    }

    public function test_logger_error_does_not_break_bot_flow(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'duvida_geral',
                'confidence' => 0.7,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->twice()
            ->andThrow(new \RuntimeException('db unavailable'));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(shadowEnabled: true, sandboxEnabled: false),
            gateResult: ['allowed' => true]
        );

        $this->assertSame('Resposta legado', $reply);
    }

    private function makeCompany(
        bool $shadowEnabled,
        bool $sandboxEnabled = false,
        ?array $testNumbers = null
    ): Company {
        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'ai_enabled' => true,
            'ai_chatbot_enabled' => true,
            'ai_chatbot_shadow_mode' => $shadowEnabled,
            'ai_chatbot_sandbox_enabled' => $sandboxEnabled,
            'ai_chatbot_test_numbers' => $testNumbers,
            'ai_chatbot_confidence_threshold' => 0.75,
            'ai_chatbot_handoff_repeat_limit' => 2,
            'ai_chatbot_auto_reply_enabled' => true,
        ]));

        return $company;
    }

    /**
     * @return array{0:string,1:array<string,mixed>|null}
     */
    private function invokeAiAssistiveDecision(
        InboundMessageService $service,
        string $legacyReply,
        Company $company,
        array $gateResult,
        string $normalizedFrom = '5511999991111',
        string $messageText = 'quero ajuda'
    ): array {
        $method = new \ReflectionMethod($service, 'applyAiAssistiveDecision');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $legacyReply,
            null,
            false,
            $company,
            $this->makeConversation(),
            $this->makeMessage(),
            $gateResult,
            $normalizedFrom,
            $messageText,
            []
        );

        return is_array($result) ? $result : [$legacyReply, null];
    }

    private function makeConversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->id = 22;
        $conversation->company_id = 10;

        return $conversation;
    }

    private function makeMessage(): Message
    {
        $message = new Message();
        $message->id = 33;
        $message->conversation_id = 22;

        return $message;
    }

    private function makeService(
        ChatbotAiIntentClassifier $chatbotAiIntentClassifier,
        ConversationAiSuggestionService $chatbotAiSuggestion,
        ChatbotAiDecisionLoggerService $chatbotAiDecisionLogger,
    ): InboundMessageService {
        $productMetrics = $this->makeMock(ProductMetricsService::class);
        $productMetrics->shouldIgnoreMissing();

        return new InboundMessageService(
            $this->makeMock(BotReplyService::class),
            $this->makeMock(WhatsAppSendService::class),
            $this->makeMock(StatefulBotService::class),
            $this->makeMock(MessageMediaStorageService::class),
            $this->makeMock(MessageDeliveryStatusService::class),
            $this->makeMock(ConversationBootstrapService::class),
            $this->makeMock(ConversationStateService::class),
            $this->makeMock(ChatbotAiDecisionService::class),
            $this->makeMock(ChatbotAiGuardService::class),
            $chatbotAiIntentClassifier,
            new ChatbotAiPolicyService(),
            $chatbotAiDecisionLogger,
            $chatbotAiSuggestion,
            $this->makeMock(AiMetricsService::class),
            $this->makeMock(AiSafetyPipelineService::class),
            $productMetrics,
        );
    }

    private function makeMock(string $abstract): MockInterface
    {
        return Mockery::mock($abstract);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }
}
