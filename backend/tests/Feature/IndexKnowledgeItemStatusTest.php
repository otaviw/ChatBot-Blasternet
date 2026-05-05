<?php

namespace Tests\Feature;

use App\Jobs\IndexKnowledgeItemJob;
use App\Models\AiCompanyKnowledge;
use App\Models\AiKnowledgeChunk;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\Ai\Rag\AiEmbeddingService;
use App\Services\Ai\Rag\AiKnowledgeChunkerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndexKnowledgeItemStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $attrs = []): AiCompanyKnowledge
    {
        $company = Company::create(['name' => 'Indexing Co']);
        CompanyBotSetting::create(['company_id' => $company->id]);

        return AiCompanyKnowledge::create(array_merge([
            'company_id' => $company->id,
            'title'      => 'Test Item',
            'content'    => str_repeat('palavra ', 60), // > 400 chars — will produce multiple chunks
            'is_active'  => true,
        ], $attrs));
    }


    public function test_observer_sets_indexing_status_to_pending_on_save(): void
    {
        $company = Company::create(['name' => 'Observer Co']);
        CompanyBotSetting::create(['company_id' => $company->id]);

        $item = AiCompanyKnowledge::withoutEvents(function () use ($company) {
            return AiCompanyKnowledge::create([
                'company_id'      => $company->id,
                'title'           => 'Pending Test',
                'content'         => str_repeat('a', 100),
                'is_active'       => true,
                'indexing_status' => 'indexed', // start as indexed
            ]);
        });

        $item->update(['title' => 'Pending Test Updated']);

        $this->assertDatabaseHas('ai_company_knowledge', [
            'id'              => $item->id,
            'indexing_status' => 'pending',
        ]);
    }


    public function test_job_marks_item_as_indexed_after_successful_run(): void
    {
        $item = $this->makeItem();

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->allows('embed')->andReturn([0.1, 0.2, 0.3]);
        $this->app->instance(AiEmbeddingService::class, $embedder);

        config(['ai.rag.embedding_model' => 'test-model']);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $this->assertDatabaseHas('ai_company_knowledge', [
            'id'              => $item->id,
            'indexing_status' => AiCompanyKnowledge::INDEXING_INDEXED,
        ]);
        $this->assertNotNull($item->fresh()->indexed_at);
    }


    public function test_job_no_ops_and_does_not_change_status_when_no_embedding_model(): void
    {
        $item = $this->makeItem(['indexing_status' => 'pending']);

        config(['ai.rag.embedding_model' => '']); // no model configured

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldNotReceive('embed');
        $this->app->instance(AiEmbeddingService::class, $embedder);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $this->assertDatabaseHas('ai_company_knowledge', [
            'id'              => $item->id,
            'indexing_status' => 'pending',
        ]);
    }


    public function test_job_marks_indexed_when_item_is_inactive(): void
    {
        $item = $this->makeItem(['is_active' => false, 'indexing_status' => 'pending']);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldNotReceive('embed');
        $this->app->instance(AiEmbeddingService::class, $embedder);

        config(['ai.rag.embedding_model' => 'test-model']);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $this->assertDatabaseHas('ai_company_knowledge', [
            'id'              => $item->id,
            'indexing_status' => AiCompanyKnowledge::INDEXING_INDEXED,
        ]);
    }


    public function test_job_failed_marks_item_as_failed(): void
    {
        $item = $this->makeItem(['indexing_status' => 'pending']);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->failed(new \RuntimeException('Embedding server unavailable'));

        $this->assertDatabaseHas('ai_company_knowledge', [
            'id'              => $item->id,
            'indexing_status' => AiCompanyKnowledge::INDEXING_FAILED,
        ]);
    }


    public function test_job_handles_deleted_item_without_error(): void
    {
        $item = $this->makeItem();
        $itemId = (int) $item->id;
        $item->delete();

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $this->app->instance(AiEmbeddingService::class, $embedder);

        config(['ai.rag.embedding_model' => 'test-model']);

        $job = new IndexKnowledgeItemJob($itemId);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $this->assertDatabaseMissing('ai_company_knowledge', ['id' => $itemId]);
    }


    public function test_job_creates_chunks_for_active_item(): void
    {
        $item = $this->makeItem([
            'content' => str_repeat('Esta é uma frase de exemplo. ', 30), // ~900 chars
        ]);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->allows('embed')->andReturn([0.5, 0.6, 0.7]);
        $this->app->instance(AiEmbeddingService::class, $embedder);

        config([
            'ai.rag.embedding_model' => 'test-model',
            'ai.rag.chunk_size'      => 300,
            'ai.rag.chunk_overlap'   => 50,
        ]);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $chunks = AiKnowledgeChunk::where('ai_knowledge_item_id', $item->id)->get();
        $this->assertGreaterThan(0, $chunks->count());
        $this->assertTrue($chunks->every(fn ($c) => $c->embedding !== null));
        $this->assertTrue($chunks->every(fn ($c) => $c->embedding_model === 'test-model'));
    }


    public function test_job_replaces_existing_chunks_on_rerun(): void
    {
        $item = $this->makeItem();

        AiKnowledgeChunk::create([
            'ai_knowledge_item_id' => $item->id,
            'company_id'           => $item->company_id,
            'title'                => 'old',
            'chunk_content'        => 'old chunk',
            'chunk_index'          => 0,
            'embedding'            => null,
            'embedding_model'      => null,
        ]);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->allows('embed')->andReturn([0.1]);
        $this->app->instance(AiEmbeddingService::class, $embedder);

        config(['ai.rag.embedding_model' => 'test-model']);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(app(AiKnowledgeChunkerService::class), $embedder);

        $this->assertDatabaseMissing('ai_knowledge_chunks', [
            'ai_knowledge_item_id' => $item->id,
            'title'                => 'old',
        ]);
    }
}
