<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Rag\AiKnowledgeRetrieverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Testa o endpoint POST /api/minha-conta/ia/sandbox.
 *
 * Casos cobertos:
 *  1. Agente normal é bloqueado (403)
 *  2. company_admin sem ai_enabled é bloqueado (403)
 *  3. company_admin com ai_enabled obtém resposta válida
 *  4. Campos obrigatórios ausentes retornam 422 de validação
 *  5. Mensagem em branco retorna 422 de validação
 *  6. Com include_rag=true, chunks são retornados
 *  7. Sem RAG chunks disponíveis, confidence_score = 0.5
 *  8. Com RAG e score, confidence_score aumenta
 *  9. Quando provider retorna ok=false, endpoint retorna 422
 */
class AiSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extensao pdo_sqlite não habilitada neste ambiente.');
        }

        parent::setUp();
    }


    private function makeAdmin(array $botAttrs = []): array
    {
        $company = Company::create(['name' => 'Sandbox Co']);
        CompanyBotSetting::create(array_merge([
            'company_id' => $company->id,
            'ai_enabled' => true,
        ], $botAttrs));

        $user = User::create([
            'name'       => 'Admin Sandbox',
            'email'      => 'admin-sandbox@test.local',
            'password'   => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        return [$company, $user];
    }

    private function makeAgent(int $companyId): User
    {
        return User::create([
            'name'       => 'Agent Sandbox',
            'email'      => 'agent-sandbox@test.local',
            'password'   => 'secret123',
            'role'       => 'agent',
            'company_id' => $companyId,
            'is_active'  => true,
            'can_use_ai' => true,
        ]);
    }

    /** Stub the AiProviderResolver to return a fake provider. */
    private function stubProvider(array $replyResult): void
    {
        config(['ai.default_provider' => 'test']);

        $fakeProvider = Mockery::mock(AiProvider::class);
        $fakeProvider->allows('reply')->andReturn($replyResult);

        $resolver = Mockery::mock(AiProviderResolver::class);
        $resolver->allows('defaultProviderName')->andReturn('test');
        $resolver->allows('resolveProviderName')->andReturn('test');
        $resolver->allows('supports')->andReturn(true);
        $resolver->allows('resolve')->andReturn($fakeProvider);

        $this->app->instance(AiProviderResolver::class, $resolver);
    }

    private function okProviderResult(string $text = 'Resposta de teste'): array
    {
        return [
            'ok'    => true,
            'text'  => $text,
            'error' => null,
            'meta'  => ['provider' => 'test', 'model' => 'test-model', 'tokens_used' => 42],
        ];
    }


    public function test_agent_is_forbidden(): void
    {
        [$company] = $this->makeAdmin();
        $agent     = $this->makeAgent($company->id);

        $response = $this->actingAs($agent)
            ->postJson('/api/minha-conta/ia/sandbox', ['message' => 'Olá']);

        $response->assertStatus(403);
    }

    public function test_company_admin_without_ai_enabled_is_forbidden(): void
    {
        [, $user] = $this->makeAdmin(['ai_enabled' => false]);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', ['message' => 'Olá']);

        $response->assertStatus(403);
    }


    public function test_admin_receives_ai_response(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult('Olá, posso ajudar!'));

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', ['message' => 'Qual é o prazo de entrega?']);

        $response->assertOk()
            ->assertJsonStructure(['ok', 'response', 'confidence_score', 'provider', 'model', 'rag_chunks_used'])
            ->assertJsonPath('ok', true)
            ->assertJsonPath('response', 'Olá, posso ajudar!');
    }


    public function test_missing_message_returns_validation_error(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_message_exceeding_max_length_returns_validation_error(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', [
                'message' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }


    public function test_without_rag_confidence_is_0_5(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', [
                'message'     => 'Qual é o prazo de entrega?',
                'include_rag' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('confidence_score', 0.5)
            ->assertJsonPath('rag_chunks_used', []);
    }

    public function test_with_rag_chunks_included_in_response(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $retriever = Mockery::mock(AiKnowledgeRetrieverService::class);
        $retriever->allows('retrieve')->andReturn([
            ['title' => 'Prazo de entrega', 'content' => 'O prazo é de 5 dias úteis.', 'score' => 0.85],
        ]);
        $this->app->instance(AiKnowledgeRetrieverService::class, $retriever);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', [
                'message'     => 'Qual é o prazo de entrega?',
                'include_rag' => true,
            ]);

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(1, $data['rag_chunks_used']);
        $this->assertEquals('Prazo de entrega', $data['rag_chunks_used'][0]['title']);
        $this->assertGreaterThan(0.5, $data['confidence_score']);
    }

    public function test_with_rag_but_no_chunks_confidence_is_0_5(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $retriever = Mockery::mock(AiKnowledgeRetrieverService::class);
        $retriever->allows('retrieve')->andReturn([]);
        $this->app->instance(AiKnowledgeRetrieverService::class, $retriever);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', [
                'message'     => 'Pergunta sem resposta na base',
                'include_rag' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('confidence_score', 0.5)
            ->assertJsonPath('rag_chunks_used', []);
    }

    public function test_rag_chunk_score_raises_confidence(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider($this->okProviderResult());

        $retriever = Mockery::mock(AiKnowledgeRetrieverService::class);
        $retriever->allows('retrieve')->andReturn([
            ['title' => 'Info', 'content' => 'Conteúdo relevante.', 'score' => 1.0],
        ]);
        $this->app->instance(AiKnowledgeRetrieverService::class, $retriever);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', [
                'message'     => 'Pergunta',
                'include_rag' => true,
            ]);

        $response->assertOk();
        $this->assertEquals(0.97, $response->json('confidence_score'));
    }


    public function test_provider_failure_returns_422(): void
    {
        [, $user] = $this->makeAdmin();

        $failProvider = Mockery::mock(AiProvider::class);
        $failProvider->allows('reply')->andReturn([
            'ok'    => false,
            'text'  => null,
            'error' => 'connection refused',
            'meta'  => ['message' => 'Servidor de IA indisponível'],
        ]);

        $resolver = Mockery::mock(AiProviderResolver::class);
        $resolver->allows('defaultProviderName')->andReturn('test');
        $resolver->allows('resolveProviderName')->andReturn('test');
        $resolver->allows('supports')->andReturn(true);
        $resolver->allows('resolve')->andReturn($failProvider);
        $this->app->instance(AiProviderResolver::class, $resolver);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', ['message' => 'Teste']);

        $response->assertStatus(422);
    }


    public function test_tokens_used_is_included_when_provider_returns_it(): void
    {
        [, $user] = $this->makeAdmin();
        $this->stubProvider([
            'ok'    => true,
            'text'  => 'Resposta',
            'error' => null,
            'meta'  => ['tokens_used' => 77],
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/minha-conta/ia/sandbox', ['message' => 'Teste']);

        $response->assertOk()
            ->assertJsonPath('tokens_used', 77);
    }
}

