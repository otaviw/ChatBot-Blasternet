<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa:
 *  1. AiMetricsService::normalizeErrorType (unit dentro de feature test)
 *  2. AiMetricsService::record — cria AiUsageLog com todos os campos de métrica
 *  3. AiMetricsService::updateFromProviderResult — atualiza log existente
 *  4. Endpoint GET /api/minha-conta/ia/metricas — retorno correto e filtros
 */
class AiMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite não habilitada neste ambiente.');
        }

        parent::setUp();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeAdminUser(): array
    {
        $company = Company::create(['name' => 'Metrics Co']);
        CompanyBotSetting::create([
            'company_id' => $company->id,
            'ai_enabled' => true,
        ]);
        $user = User::create([
            'name' => 'Admin Metrics',
            'email' => 'admin-metrics@test.local',
            'password' => 'secret123',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    private function seedLogs(int $companyId, array $overrides = [], int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiUsageLog::create(array_merge([
                'company_id' => $companyId,
                'user_id' => null,
                'conversation_id' => null,
                'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
                'provider' => 'ollama',
                'model' => 'llama3',
                'feature' => AiUsageLog::FEATURE_INTERNAL_CHAT,
                'status' => AiUsageLog::STATUS_OK,
                'message_length' => 100,
                'tokens_used' => 250,
                'response_time_ms' => 800,
                'error_type' => null,
                'created_at' => now(),
            ], $overrides));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. normalizeErrorType
    // ─────────────────────────────────────────────────────────────────────────

    public function test_normalize_error_type_timeout(): void
    {
        $this->assertSame(AiUsageLog::ERROR_TIMEOUT, AiMetricsService::normalizeErrorType('timeout'));
        $this->assertSame(AiUsageLog::ERROR_TIMEOUT, AiMetricsService::normalizeErrorType('request timed out'));
        $this->assertSame(AiUsageLog::ERROR_TIMEOUT, AiMetricsService::normalizeErrorType('Connection timed_out'));
    }

    public function test_normalize_error_type_provider_unavailable(): void
    {
        $this->assertSame(AiUsageLog::ERROR_PROVIDER_UNAVAILABLE, AiMetricsService::normalizeErrorType('provider_exception'));
        $this->assertSame(AiUsageLog::ERROR_PROVIDER_UNAVAILABLE, AiMetricsService::normalizeErrorType('connection refused'));
        $this->assertSame(AiUsageLog::ERROR_PROVIDER_UNAVAILABLE, AiMetricsService::normalizeErrorType('service unavailable'));
    }

    public function test_normalize_error_type_validation(): void
    {
        $this->assertSame(AiUsageLog::ERROR_VALIDATION, AiMetricsService::normalizeErrorType('validation_error'));
        $this->assertSame(AiUsageLog::ERROR_VALIDATION, AiMetricsService::normalizeErrorType('invalid_provider_result'));
    }

    public function test_normalize_error_type_unknown_fallback(): void
    {
        $this->assertSame(AiUsageLog::ERROR_UNKNOWN, AiMetricsService::normalizeErrorType('some_random_error'));
        $this->assertSame(AiUsageLog::ERROR_UNKNOWN, AiMetricsService::normalizeErrorType(null));
        $this->assertSame(AiUsageLog::ERROR_UNKNOWN, AiMetricsService::normalizeErrorType(''));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. AiMetricsService::record
    // ─────────────────────────────────────────────────────────────────────────

    public function test_record_creates_log_with_all_metrics_fields(): void
    {
        [$company] = $this->makeAdminUser();

        /** @var AiMetricsService $service */
        $service = $this->app->make(AiMetricsService::class);

        $log = $service->record(
            companyId: $company->id,
            userId: null,
            conversationId: null,
            provider: 'ollama',
            model: 'llama3',
            feature: AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            status: AiUsageLog::STATUS_OK,
            responseTimeMs: 1234,
            tokensUsed: 512,
            errorType: null
        );

        $this->assertInstanceOf(AiUsageLog::class, $log);
        $this->assertSame('ollama', $log->provider);
        $this->assertSame('llama3', $log->model);
        $this->assertSame(AiUsageLog::FEATURE_CONVERSATION_SUGGESTION, $log->feature);
        $this->assertSame(AiUsageLog::STATUS_OK, $log->status);
        $this->assertSame(1234, $log->response_time_ms);
        $this->assertSame(512, $log->tokens_used);
        $this->assertNull($log->error_type);

        $this->assertDatabaseHas('ai_usage_logs', [
            'company_id' => $company->id,
            'provider' => 'ollama',
            'model' => 'llama3',
            'feature' => AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            'status' => AiUsageLog::STATUS_OK,
            'response_time_ms' => 1234,
            'tokens_used' => 512,
        ]);
    }

    public function test_record_error_log_sets_error_type(): void
    {
        [$company] = $this->makeAdminUser();

        /** @var AiMetricsService $service */
        $service = $this->app->make(AiMetricsService::class);

        $log = $service->record(
            companyId: $company->id,
            userId: null,
            conversationId: null,
            provider: 'ollama',
            model: null,
            feature: AiUsageLog::FEATURE_CHATBOT,
            status: AiUsageLog::STATUS_ERROR,
            responseTimeMs: 500,
            tokensUsed: null,
            errorType: AiUsageLog::ERROR_TIMEOUT
        );

        $this->assertSame(AiUsageLog::STATUS_ERROR, $log->status);
        $this->assertSame(AiUsageLog::ERROR_TIMEOUT, $log->error_type);
        $this->assertSame(AiUsageLog::TYPE_CHATBOT, $log->type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. AiMetricsService::updateFromProviderResult
    // ─────────────────────────────────────────────────────────────────────────

    public function test_update_from_provider_result_ok(): void
    {
        [$company] = $this->makeAdminUser();

        $log = AiUsageLog::create([
            'company_id' => $company->id,
            'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
            'message_length' => 50,
            'created_at' => now(),
        ]);

        /** @var AiMetricsService $service */
        $service = $this->app->make(AiMetricsService::class);

        $providerResult = [
            'ok' => true,
            'text' => 'Some AI response',
            'tokens_used' => 300,
        ];

        $service->updateFromProviderResult($log, 'ollama', 'llama3', AiUsageLog::FEATURE_INTERNAL_CHAT, $providerResult, 950, 300);

        $log->refresh();

        $this->assertSame('ollama', $log->provider);
        $this->assertSame('llama3', $log->model);
        $this->assertSame(AiUsageLog::FEATURE_INTERNAL_CHAT, $log->feature);
        $this->assertSame(AiUsageLog::STATUS_OK, $log->status);
        $this->assertSame(950, $log->response_time_ms);
        $this->assertSame(300, $log->tokens_used);
        $this->assertNull($log->error_type);
    }

    public function test_update_from_provider_result_error_sets_error_type(): void
    {
        [$company] = $this->makeAdminUser();

        $log = AiUsageLog::create([
            'company_id' => $company->id,
            'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
            'message_length' => 50,
            'created_at' => now(),
        ]);

        /** @var AiMetricsService $service */
        $service = $this->app->make(AiMetricsService::class);

        $providerResult = [
            'ok' => false,
            'error' => 'provider_exception',
        ];

        $service->updateFromProviderResult($log, 'ollama', null, AiUsageLog::FEATURE_INTERNAL_CHAT, $providerResult, 200);

        $log->refresh();

        $this->assertSame(AiUsageLog::STATUS_ERROR, $log->status);
        $this->assertSame(AiUsageLog::ERROR_PROVIDER_UNAVAILABLE, $log->error_type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Endpoint GET /api/minha-conta/ia/metricas
    // ─────────────────────────────────────────────────────────────────────────

    public function test_metrics_endpoint_returns_correct_summary(): void
    {
        [$company, $user] = $this->makeAdminUser();

        // 3 ok + 1 error
        $this->seedLogs($company->id, ['status' => AiUsageLog::STATUS_OK, 'response_time_ms' => 1000, 'tokens_used' => 200], 3);
        $this->seedLogs($company->id, [
            'status' => AiUsageLog::STATUS_ERROR,
            'error_type' => AiUsageLog::ERROR_TIMEOUT,
            'response_time_ms' => 5000,
            'tokens_used' => null,
        ], 1);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas');

        $response->assertOk();
        $response->assertJsonPath('authenticated', true);
        $response->assertJsonPath('summary.total_requests', 4);
        $response->assertJsonPath('summary.ok_count', 3);
        $response->assertJsonPath('summary.error_count', 1);
        $this->assertEquals(25.0, $response->json('summary.error_rate_pct'));
        $response->assertJsonPath('summary.total_tokens', 600);
    }

    public function test_metrics_endpoint_by_feature_breakdown(): void
    {
        [$company, $user] = $this->makeAdminUser();

        $this->seedLogs($company->id, ['feature' => AiUsageLog::FEATURE_INTERNAL_CHAT], 2);
        $this->seedLogs($company->id, [
            'feature' => AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
        ], 3);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas');

        $response->assertOk();
        $features = collect($response->json('by_feature'));

        $suggestion = $features->firstWhere('feature', AiUsageLog::FEATURE_CONVERSATION_SUGGESTION);
        $this->assertNotNull($suggestion);
        $this->assertSame(3, $suggestion['total']);

        $chat = $features->firstWhere('feature', AiUsageLog::FEATURE_INTERNAL_CHAT);
        $this->assertNotNull($chat);
        $this->assertSame(2, $chat['total']);
    }

    public function test_metrics_endpoint_filter_by_provider(): void
    {
        [$company, $user] = $this->makeAdminUser();

        $this->seedLogs($company->id, ['provider' => 'ollama'], 3);
        $this->seedLogs($company->id, ['provider' => 'anthropic'], 2);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas?provider=ollama');

        $response->assertOk();
        $response->assertJsonPath('summary.total_requests', 3);
        $this->assertSame('ollama', $response->json('filters.provider'));
    }

    public function test_metrics_endpoint_by_error_type(): void
    {
        [$company, $user] = $this->makeAdminUser();

        $this->seedLogs($company->id, [
            'status' => AiUsageLog::STATUS_ERROR,
            'error_type' => AiUsageLog::ERROR_TIMEOUT,
        ], 2);
        $this->seedLogs($company->id, [
            'status' => AiUsageLog::STATUS_ERROR,
            'error_type' => AiUsageLog::ERROR_PROVIDER_UNAVAILABLE,
        ], 1);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas');

        $response->assertOk();
        $errors = collect($response->json('by_error_type'));

        $timeout = $errors->firstWhere('error_type', AiUsageLog::ERROR_TIMEOUT);
        $this->assertNotNull($timeout);
        $this->assertSame(2, $timeout['count']);
    }

    public function test_metrics_endpoint_daily_series_has_all_days(): void
    {
        [$company, $user] = $this->makeAdminUser();

        $this->seedLogs($company->id);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas?date_from='.now()->subDays(6)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk();
        $daily = $response->json('daily');
        $this->assertCount(7, $daily);
    }

    public function test_metrics_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/minha-conta/ia/metricas');
        // Auth middleware retorna 401 para não-autenticado
        $response->assertStatus(401);
    }

    public function test_metrics_endpoint_requires_ai_enabled(): void
    {
        $company = Company::create(['name' => 'No AI Co']);
        // ai_enabled = false (default)
        CompanyBotSetting::create(['company_id' => $company->id, 'ai_enabled' => false]);
        $user = User::create([
            'name' => 'NoAI User',
            'email' => 'noai@test.local',
            'password' => 'secret',
            'role' => 'company',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/ia/metricas');
        $response->assertStatus(403);
    }

    public function test_metrics_endpoint_company_isolation(): void
    {
        [$companyA, $userA] = $this->makeAdminUser();

        $companyB = Company::create(['name' => 'Company B']);
        CompanyBotSetting::create(['company_id' => $companyB->id, 'ai_enabled' => true]);

        // Logs de B não devem aparecer para A
        $this->seedLogs($companyA->id, ['provider' => 'ollama-a'], 2);
        $this->seedLogs($companyB->id, ['provider' => 'ollama-b'], 5);

        $response = $this->actingAs($userA)->getJson('/api/minha-conta/ia/metricas');

        $response->assertOk();
        $response->assertJsonPath('summary.total_requests', 2);

        $providers = collect($response->json('by_provider'));
        $this->assertNull($providers->firstWhere('provider', 'ollama-b'));
    }
}
