<?php

namespace Tests\Unit\Services\Ai\Rag;

use App\Models\AiKnowledgeChunk;
use App\Services\Ai\AiCompanyKnowledgeService;
use App\Services\Ai\Rag\AiEmbeddingService;
use App\Services\Ai\Rag\AiKnowledgeRetrieverService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AiKnowledgeRetrieverServiceTest extends TestCase
{
    private AiEmbeddingService&MockInterface $embeddingService;
    private AiCompanyKnowledgeService&MockInterface $knowledgeService;
    private AiKnowledgeRetrieverService $retriever;

    protected function setUp(): void
    {
        parent::setUp();

        $this->embeddingService = Mockery::mock(AiEmbeddingService::class);
        $this->knowledgeService = Mockery::mock(AiCompanyKnowledgeService::class);

        $this->retriever = new AiKnowledgeRetrieverService(
            $this->embeddingService,
            $this->knowledgeService
        );
    }

    public function test_returns_static_fallback_when_rag_disabled(): void
    {
        config(['ai.rag.enabled' => false]);

        $fakeItem = (object) ['title' => 'T1', 'content' => 'C1'];
        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->once()
            ->with(1, 3)
            ->andReturn(collect([$fakeItem]));

        $results = $this->retriever->retrieve(1, 'some query', 3);

        $this->assertCount(1, $results);
        $this->assertSame('T1', $results[0]['title']);
        $this->assertNull($results[0]['score']);
    }

    public function test_returns_static_fallback_when_query_is_null(): void
    {
        config(['ai.rag.enabled' => true]);

        $fakeItem = (object) ['title' => 'FAQ', 'content' => 'Answer'];
        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->once()
            ->andReturn(collect([$fakeItem]));

        $results = $this->retriever->retrieve(1, null, 3);

        $this->assertCount(1, $results);
    }

    public function test_returns_static_fallback_when_embedding_fails(): void
    {
        config(['ai.rag.enabled' => true]);

        $this->embeddingService
            ->shouldReceive('embed')
            ->once()
            ->andReturn(null);

        $fakeItem = (object) ['title' => 'T', 'content' => 'C'];
        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->once()
            ->andReturn(collect([$fakeItem]));

        $results = $this->retriever->retrieve(1, 'any query', 3);

        $this->assertCount(1, $results);
    }

    public function test_returns_static_fallback_when_company_id_is_zero(): void
    {
        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->never();

        $results = $this->retriever->retrieve(0, 'query', 3);

        $this->assertSame([], $results);
    }

    public function test_static_items_preserves_title_and_content(): void
    {
        $fakeItem = (object) ['title' => 'Relevant', 'content' => 'Best match'];
        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->with(1, 1)
            ->andReturn(collect([$fakeItem]));

        $results = $this->retriever->staticItems(1, 1);

        $this->assertCount(1, $results);
        $this->assertSame('Relevant', $results[0]['title']);
        $this->assertSame('Best match', $results[0]['content']);
        $this->assertNull($results[0]['score']);
    }

    public function test_static_items_returns_correct_shape(): void
    {
        $items = collect([
            (object) ['title' => 'Title A', 'content' => 'Content A'],
            (object) ['title' => 'Title B', 'content' => 'Content B'],
        ]);

        $this->knowledgeService
            ->shouldReceive('getActiveForCompany')
            ->with(5, 2)
            ->andReturn($items);

        $results = $this->retriever->staticItems(5, 2);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertArrayHasKey('title', $result);
            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('score', $result);
            $this->assertNull($result['score']);
        }
    }

    public function test_retrieve_returns_empty_for_negative_company(): void
    {
        $results = $this->retriever->retrieve(-1, 'query', 3);

        $this->assertSame([], $results);
    }

    private function makeChunk(int $itemId, string $title, string $content, array $embedding): object
    {
        return (object) [
            'ai_knowledge_item_id' => $itemId,
            'title' => $title,
            'chunk_content' => $content,
            'chunk_index' => 0,
            'embedding' => json_encode($embedding),
        ];
    }
}
