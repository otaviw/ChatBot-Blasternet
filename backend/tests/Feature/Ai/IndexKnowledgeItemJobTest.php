<?php

namespace Tests\Feature\Ai;

use App\Jobs\IndexKnowledgeItemJob;
use App\Models\AiCompanyKnowledge;
use App\Models\AiKnowledgeChunk;
use App\Services\Ai\Rag\AiEmbeddingService;
use App\Services\Ai\Rag\AiKnowledgeChunkerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndexKnowledgeItemJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.rag.embedding_model' => 'test-embed-model']);
        config(['ai.rag.chunk_size' => 400]);
        config(['ai.rag.chunk_overlap' => 50]);
    }

    public function test_no_op_when_embedding_model_not_configured(): void
    {
        config(['ai.rag.embedding_model' => '']);

        $item = AiCompanyKnowledge::factory()->create(['content' => 'Some content here.']);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldNotReceive('embed');

        $this->app->instance(AiEmbeddingService::class, $embedder);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $this->assertDatabaseCount('ai_knowledge_chunks', 0);
    }

    public function test_creates_chunks_for_active_item(): void
    {
        $content = "First paragraph of knowledge content.\n\nSecond paragraph with more details.";
        $item = AiCompanyKnowledge::factory()->create([
            'content' => $content,
            'is_active' => true,
        ]);

        $fakeEmbedding = array_fill(0, 8, 0.1);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldReceive('embed')
            ->andReturn($fakeEmbedding);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $this->assertDatabaseHas('ai_knowledge_chunks', [
            'ai_knowledge_item_id' => $item->id,
            'company_id' => $item->company_id,
            'embedding_model' => 'test-embed-model',
        ]);
    }

    public function test_deletes_old_chunks_before_reindexing(): void
    {
        $item = AiCompanyKnowledge::factory()->create([
            'content' => 'Original content.',
            'is_active' => true,
        ]);

        AiKnowledgeChunk::create([
            'ai_knowledge_item_id' => $item->id,
            'company_id' => $item->company_id,
            'title' => 'Old',
            'chunk_content' => 'Stale chunk',
            'chunk_index' => 0,
            'embedding' => json_encode([0.1, 0.2]),
            'embedding_model' => 'old-model',
        ]);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldReceive('embed')->andReturn([0.5, 0.5]);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $this->assertDatabaseMissing('ai_knowledge_chunks', ['embedding_model' => 'old-model']);
        $this->assertDatabaseHas('ai_knowledge_chunks', ['embedding_model' => 'test-embed-model']);
    }

    public function test_removes_chunks_for_inactive_item(): void
    {
        $item = AiCompanyKnowledge::factory()->create([
            'content' => 'Some content.',
            'is_active' => false,
        ]);

        AiKnowledgeChunk::create([
            'ai_knowledge_item_id' => $item->id,
            'company_id' => $item->company_id,
            'title' => 'Old',
            'chunk_content' => 'Old chunk',
            'chunk_index' => 0,
            'embedding' => json_encode([0.1]),
            'embedding_model' => 'test-embed-model',
        ]);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldNotReceive('embed');

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $this->assertDatabaseCount('ai_knowledge_chunks', 0);
    }

    public function test_no_op_for_nonexistent_item(): void
    {
        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldNotReceive('embed');

        $job = new IndexKnowledgeItemJob(99999);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $this->assertDatabaseCount('ai_knowledge_chunks', 0);
    }

    public function test_chunk_stored_without_embedding_when_embed_returns_null(): void
    {
        $item = AiCompanyKnowledge::factory()->create([
            'content' => 'Some content for the knowledge base.',
            'is_active' => true,
        ]);

        $embedder = Mockery::mock(AiEmbeddingService::class);
        $embedder->shouldReceive('embed')->andReturn(null);

        $job = new IndexKnowledgeItemJob((int) $item->id);
        $job->handle(new AiKnowledgeChunkerService(), $embedder);

        $chunk = AiKnowledgeChunk::where('ai_knowledge_item_id', $item->id)->first();
        $this->assertNotNull($chunk);
        $this->assertNull($chunk->embedding);
        $this->assertNull($chunk->embedding_model);
    }
}
