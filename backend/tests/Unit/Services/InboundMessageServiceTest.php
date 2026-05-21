<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiMetricsService;
use App\Services\Ai\AiAuditService;
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
use App\Services\Ai\Safety\AiSafetyResult;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class InboundMessageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'ai.provider' => 'test',
            'ai.model' => 'test-model',
        ]);
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

    public function test_first_auto_reply_gets_default_welcome_when_company_welcome_is_blank(): void
    {
        $service = $this->makeService(
            chatbotAiIntentClassifier: $this->makeMock(ChatbotAiIntentClassifier::class),
            chatbotAiSuggestion: $this->makeMock(ConversationAiSuggestionService::class),
            chatbotAiDecisionLogger: $this->makeMock(ChatbotAiDecisionLoggerService::class),
        );

        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'welcome_message' => '',
        ]));

        $method = new \ReflectionMethod($service, 'prependWelcomeToFirstAutoReply');
        $method->setAccessible(true);

        $result = $method->invoke($service, $company, 'Perfeito. Para consultar boletos em aberto, informe o CPF/CNPJ.');

        $this->assertSame(
            "Olá, tudo bem? Já entendi que você precisa de ajuda com o financeiro. Vou te orientar por aqui.\n\nPerfeito. Para consultar boletos em aberto, informe o CPF/CNPJ.",
            $result
        );
    }

    public function test_first_auto_reply_uses_contextual_appointment_welcome(): void
    {
        $service = $this->makeService(
            chatbotAiIntentClassifier: $this->makeMock(ChatbotAiIntentClassifier::class),
            chatbotAiSuggestion: $this->makeMock(ConversationAiSuggestionService::class),
            chatbotAiDecisionLogger: $this->makeMock(ChatbotAiDecisionLoggerService::class),
        );

        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'welcome_message' => 'Oi. Como posso ajudar?',
        ]));

        $method = new \ReflectionMethod($service, 'prependWelcomeToFirstAutoReply');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $company,
            'Atendente: Desenvolvedor',
            'quero agendamento',
            'agendamento'
        );

        $this->assertSame(
            "Olá, tudo bem? Vou te ajudar com o agendamento.\n\nAtendente: Desenvolvedor",
            $result
        );
    }

    public function test_first_auto_reply_removes_duplicated_leading_line(): void
    {
        $service = $this->makeService(
            chatbotAiIntentClassifier: $this->makeMock(ChatbotAiIntentClassifier::class),
            chatbotAiSuggestion: $this->makeMock(ConversationAiSuggestionService::class),
            chatbotAiDecisionLogger: $this->makeMock(ChatbotAiDecisionLoggerService::class),
        );

        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'welcome_message' => '',
        ]));

        $method = new \ReflectionMethod($service, 'prependWelcomeToFirstAutoReply');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $company,
            "Escolha uma das opções para seguir atendimento.\nEscolha uma das opções para seguir atendimento.\nBoleto",
            'oi quero boleto',
            'financeiro'
        );

        $this->assertSame(
            "Olá, tudo bem? Já entendi que você precisa de ajuda com o financeiro. Vou te orientar por aqui.\n\nEscolha uma das opções para seguir atendimento.\nBoleto",
            $result
        );
    }

    public function test_first_auto_reply_does_not_duplicate_existing_greeting(): void
    {
        $service = $this->makeService(
            chatbotAiIntentClassifier: $this->makeMock(ChatbotAiIntentClassifier::class),
            chatbotAiSuggestion: $this->makeMock(ConversationAiSuggestionService::class),
            chatbotAiDecisionLogger: $this->makeMock(ChatbotAiDecisionLoggerService::class),
        );

        $company = new Company(['name' => 'Empresa Teste']);
        $company->id = 10;
        $company->setRelation('botSetting', new CompanyBotSetting([
            'company_id' => 10,
            'welcome_message' => '',
        ]));

        $method = new \ReflectionMethod($service, 'prependWelcomeToFirstAutoReply');
        $method->setAccessible(true);

        $result = $method->invoke($service, $company, 'Oi. Como posso ajudar?');

        $this->assertSame('Oi. Como posso ajudar?', $result);
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

    public function test_safety_prompt_injection_blocks_before_intent_classifier_and_falls_back_to_legacy(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier->shouldNotReceive('classify');

        $safetyPipeline = Mockery::mock(AiSafetyPipelineService::class);
        $safetyPipeline
            ->shouldReceive('run')
            ->once()
            ->andReturn(new AiSafetyResult(
                blocked: true,
                sanitizedInput: 'texto sanitizado',
                blockReason: 'prompt_injection:jailbreak',
                blockStage: 'prompt_injection',
                flags: ['prompt_injection:jailbreak']
            ));

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $safety = is_array($payload['gate_result']['safety'] ?? null)
                    ? $payload['gate_result']['safety']
                    : [];

                return ($payload['mode'] ?? null) === 'shadow'
                    && ($payload['action'] ?? null) === 'fallback_legacy'
                    && ($payload['error'] ?? null) === 'safety_blocked'
                    && ($safety['blocked'] ?? null) === true
                    && ($safety['stage'] ?? null) === 'prompt_injection';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            safetyPipeline: $safetyPipeline,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(shadowEnabled: true, sandboxEnabled: false),
            gateResult: ['allowed' => true]
        );

        $this->assertSame('Resposta legado', $reply);
    }

    public function test_safety_pii_redaction_sanitizes_input_before_intent_classifier(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->with(
                Mockery::type(Conversation::class),
                Mockery::type(CompanyBotSetting::class),
                'texto com [PII_REDACTED]',
                Mockery::type('array')
            )
            ->andReturn([
                'intent' => 'duvida_geral',
                'confidence' => 0.83,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $safetyPipeline = Mockery::mock(AiSafetyPipelineService::class);
        $safetyPipeline
            ->shouldReceive('run')
            ->once()
            ->andReturn(new AiSafetyResult(
                blocked: false,
                sanitizedInput: 'texto com [PII_REDACTED]',
                blockReason: null,
                blockStage: null,
                flags: ['pii:cpf']
            ));

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::type('array'));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            safetyPipeline: $safetyPipeline,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(shadowEnabled: true, sandboxEnabled: false),
            gateResult: ['allowed' => true],
            messageText: 'texto com cpf 123.456.789-00'
        );

        $this->assertSame('Resposta legado', $reply);
    }

    public function test_safety_pipeline_error_falls_back_to_legacy_and_logs_error(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier->shouldNotReceive('classify');

        $safetyPipeline = Mockery::mock(AiSafetyPipelineService::class);
        $safetyPipeline
            ->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('safety unavailable'));

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return ($payload['mode'] ?? null) === 'shadow'
                    && ($payload['action'] ?? null) === 'fallback_legacy'
                    && ($payload['error'] ?? null) === 'safety_pipeline_exception';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            safetyPipeline: $safetyPipeline,
        );

        [$reply] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Resposta legado',
            company: $this->makeCompany(shadowEnabled: true, sandboxEnabled: false),
            gateResult: ['allowed' => true]
        );

        $this->assertSame('Resposta legado', $reply);
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

    public function test_sandbox_on_stateful_interactive_can_apply_ai_and_preserve_message_shape(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion
            ->shouldReceive('generateSuggestion')
            ->once()
            ->andReturn([
                'suggestion' => 'Resposta assistida da IA',
                'confidence_score' => 0.9,
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
                    && ($comparison['legacy_reply'] ?? null) === 'Texto legado do menu'
                    && ($comparison['final_reply'] ?? null) === 'Resposta assistida da IA';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
        );

        $originalMessage = [
            'type' => 'interactive_buttons',
            'body_text' => 'Texto legado do menu',
            'buttons' => [
                ['id' => '1', 'title' => 'Suporte'],
                ['id' => '2', 'title' => 'Vendas'],
            ],
            'header_text' => 'Header legado',
            'footer_text' => 'Footer legado',
        ];

        [$reply, $replyMessage] = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Texto legado do menu',
            company: $this->makeCompany(
                shadowEnabled: false,
                sandboxEnabled: true,
                testNumbers: ['5511999991111']
            ),
            gateResult: ['allowed' => true],
            normalizedFrom: '5511999991111',
            messageText: 'quero ajuda com a internet',
            replyMessage: $originalMessage,
            statefulHandled: true
        );

        $this->assertSame('Resposta assistida da IA', $reply);
        $this->assertIsArray($replyMessage);
        $this->assertSame('interactive_buttons', $replyMessage['type'] ?? null);
        $this->assertSame('Resposta assistida da IA', $replyMessage['body_text'] ?? null);
        $this->assertSame($originalMessage['buttons'], $replyMessage['buttons'] ?? null);
        $this->assertSame($originalMessage['header_text'], $replyMessage['header_text'] ?? null);
        $this->assertSame($originalMessage['footer_text'], $replyMessage['footer_text'] ?? null);
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

    public function test_sandbox_log_includes_tokens_used_when_provider_returns_usage(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion
            ->shouldReceive('generateSuggestion')
            ->once()
            ->andReturn([
                'suggestion' => 'Resposta assistida com token',
                'confidence_score' => 0.9,
                'used_rag' => false,
                'rag_chunks' => [],
                'provider' => 'test-provider-runtime',
                'model' => 'test-model-runtime',
                'tokens_used' => 77,
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
                return ($payload['mode'] ?? null) === 'sandbox'
                    && ($payload['tokens_used'] ?? null) === 77
                    && ($payload['provider'] ?? null) === 'test-provider-runtime'
                    && ($payload['model'] ?? null) === 'test-model-runtime';
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

        $this->assertSame('Resposta assistida com token', $reply);
    }

    public function test_sandbox_log_keeps_tokens_used_null_when_provider_does_not_return_usage(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

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
                return ($payload['mode'] ?? null) === 'shadow'
                    && array_key_exists('tokens_used', $payload)
                    && is_null($payload['tokens_used']);
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
                shadowEnabled: true,
                sandboxEnabled: false,
                testNumbers: []
            ),
            gateResult: ['allowed' => true],
            normalizedFrom: '5511999991111'
        );

        $this->assertNotSame('', trim($reply));
    }

    public function test_active_ai_routes_known_intent_to_stateful_menu_action(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'agendamento',
                'confidence' => 0.95,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $statefulBot = Mockery::mock(StatefulBotService::class);
        $statefulBot
            ->shouldReceive('handleAiResolvedMenuAction')
            ->once()
            ->with(Mockery::type(Company::class), Mockery::type(Conversation::class), 'agendamento', 'quero agendar')
            ->andReturn([
                'handled' => true,
                'reply_text' => 'Qual dia voce prefere?',
                'reply_message' => null,
                'should_handoff' => false,
            ]);

        $decision = Mockery::mock(ChatbotAiDecisionService::class);
        $decision->shouldReceive('shouldUseAi')->once()->andReturnTrue();

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger
            ->shouldReceive('logDecision')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $comparison = is_array($payload['gate_result']['reply_comparison'] ?? null)
                    ? $payload['gate_result']['reply_comparison']
                    : [];

                return ($payload['mode'] ?? null) === 'active'
                    && ($payload['intent'] ?? null) === 'agendamento'
                    && ($payload['action'] ?? null) === ChatbotAiPolicyService::ACTION_ROUTE_TO_APPOINTMENT_FLOW
                    && ($comparison['final_reply'] ?? null) === 'Qual dia voce prefere?';
            }));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            chatbotAiDecision: $decision,
            statefulBot: $statefulBot,
        );

        $result = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Menu legado',
            company: $this->makeCompany(shadowEnabled: false, sandboxEnabled: false),
            gateResult: ['allowed' => true],
            messageText: 'quero agendar'
        );

        $this->assertSame('Qual dia voce prefere?', $result['reply']);
        $this->assertIsArray($result['stateful_result']);
        $this->assertFalse((bool) $result['force_human_handoff']);
    }

    public function test_active_ai_does_not_handoff_attendant_request_without_direct_menu_option(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'falar_com_atendente',
                'confidence' => 0.95,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $statefulBot = Mockery::mock(StatefulBotService::class);
        $statefulBot
            ->shouldReceive('handleAiResolvedMenuAction')
            ->once()
            ->andReturnNull();
        $statefulBot
            ->shouldReceive('hasDirectAttendantHandoffOption')
            ->once()
            ->andReturnFalse();

        $decision = Mockery::mock(ChatbotAiDecisionService::class);
        $decision->shouldReceive('shouldUseAi')->once()->andReturnTrue();

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger->shouldReceive('logDecision')->once()->with(Mockery::type('array'));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            chatbotAiDecision: $decision,
            statefulBot: $statefulBot,
        );

        $result = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Menu legado',
            company: $this->makeCompany(
                shadowEnabled: false,
                sandboxEnabled: false,
                autoReplyEnabled: false
            ),
            gateResult: ['allowed' => true],
            messageText: 'quero falar com atendente'
        );

        $this->assertSame('Menu legado', $result['reply']);
        $this->assertFalse((bool) $result['force_human_handoff']);
        $this->assertNull($result['stateful_result']);
    }

    public function test_active_ai_routes_attendant_request_when_direct_menu_option_exists(): void
    {
        $chatbotAiSuggestion = Mockery::mock(ConversationAiSuggestionService::class);
        $chatbotAiSuggestion->shouldNotReceive('generateSuggestion');

        $intentClassifier = Mockery::mock(ChatbotAiIntentClassifier::class);
        $intentClassifier
            ->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'falar_com_atendente',
                'confidence' => 0.95,
                'extracted_data' => [],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'provider_classification',
            ]);

        $statefulBot = Mockery::mock(StatefulBotService::class);
        $statefulBot
            ->shouldReceive('handleAiResolvedMenuAction')
            ->once()
            ->andReturn([
                'handled' => true,
                'reply_text' => 'Certo. Vou te encaminhar para um atendente.',
                'reply_message' => null,
                'should_handoff' => true,
                'set_handling_mode' => 'human',
                'set_assigned_type' => 'area',
                'set_assigned_id' => 44,
                'set_current_area_id' => 44,
            ]);

        $decision = Mockery::mock(ChatbotAiDecisionService::class);
        $decision->shouldReceive('shouldUseAi')->once()->andReturnTrue();

        $decisionLogger = Mockery::mock(ChatbotAiDecisionLoggerService::class);
        $decisionLogger->shouldReceive('logDecision')->once()->with(Mockery::type('array'));

        $service = $this->makeService(
            chatbotAiIntentClassifier: $intentClassifier,
            chatbotAiSuggestion: $chatbotAiSuggestion,
            chatbotAiDecisionLogger: $decisionLogger,
            chatbotAiDecision: $decision,
            statefulBot: $statefulBot,
        );

        $result = $this->invokeAiAssistiveDecision(
            $service,
            legacyReply: 'Menu legado',
            company: $this->makeCompany(shadowEnabled: false, sandboxEnabled: false),
            gateResult: ['allowed' => true],
            messageText: 'quero falar com atendente'
        );

        $this->assertSame('Certo. Vou te encaminhar para um atendente.', $result['reply']);
        $this->assertIsArray($result['stateful_result']);
        $this->assertFalse((bool) $result['force_human_handoff']);
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
        ?array $testNumbers = null,
        bool $autoReplyEnabled = true
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
            'ai_chatbot_auto_reply_enabled' => $autoReplyEnabled,
            'ai_chatbot_mode' => ChatbotAiDecisionService::MODE_ALWAYS,
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
        string $messageText = 'quero ajuda',
        mixed $replyMessage = null,
        bool $statefulHandled = false
    ): array {
        $method = new \ReflectionMethod($service, 'applyAiAssistiveDecision');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $legacyReply,
            $replyMessage,
            $statefulHandled,
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
        ?AiSafetyPipelineService $safetyPipeline = null,
        ?ChatbotAiDecisionService $chatbotAiDecision = null,
        ?StatefulBotService $statefulBot = null,
    ): InboundMessageService {
        $productMetrics = $this->makeMock(ProductMetricsService::class);
        $productMetrics->shouldIgnoreMissing();
        $aiAudit = $this->makeMock(AiAuditService::class);
        $aiAudit->shouldIgnoreMissing();
        $safety = $safetyPipeline ?? $this->makeMock(AiSafetyPipelineService::class);
        if ($safetyPipeline === null) {
            $safety
                ->shouldReceive('run')
                ->andReturnUsing(static fn (string $input): AiSafetyResult => new AiSafetyResult(false, $input));
        }

        return new InboundMessageService(
            $this->makeMock(BotReplyService::class),
            $this->makeMock(WhatsAppSendService::class),
            $statefulBot ?? $this->makeMock(StatefulBotService::class),
            $this->makeMock(MessageMediaStorageService::class),
            $this->makeMock(MessageDeliveryStatusService::class),
            $this->makeMock(ConversationBootstrapService::class),
            $this->makeMock(ConversationStateService::class),
            $chatbotAiDecision ?? $this->makeMock(ChatbotAiDecisionService::class),
            $this->makeMock(ChatbotAiGuardService::class),
            $chatbotAiIntentClassifier,
            new ChatbotAiPolicyService(),
            $chatbotAiDecisionLogger,
            $chatbotAiSuggestion,
            $aiAudit,
            $this->makeMock(AiMetricsService::class),
            $safety,
            $productMetrics,
        );
    }

    private function makeMock(string $abstract): MockInterface
    {
        return Mockery::mock($abstract);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
