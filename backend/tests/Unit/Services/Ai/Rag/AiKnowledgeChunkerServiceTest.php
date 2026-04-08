<?php

namespace Tests\Unit\Services\Ai\Rag;

use App\Services\Ai\Rag\AiKnowledgeChunkerService;
use PHPUnit\Framework\TestCase;

class AiKnowledgeChunkerServiceTest extends TestCase
{
    private AiKnowledgeChunkerService $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new AiKnowledgeChunkerService();
    }

    public function test_empty_content_returns_empty_array(): void
    {
        $this->assertSame([], $this->chunker->chunk(''));
        $this->assertSame([], $this->chunker->chunk('   '));
    }

    public function test_short_content_returns_single_chunk(): void
    {
        $content = 'This is a short text.';
        $chunks = $this->chunker->chunk($content, 400);

        $this->assertCount(1, $chunks);
        $this->assertSame($content, $chunks[0]);
    }

    public function test_content_at_exact_limit_is_single_chunk(): void
    {
        $content = str_repeat('a', 400);
        $chunks = $this->chunker->chunk($content, 400);

        $this->assertCount(1, $chunks);
    }

    public function test_two_paragraphs_under_limit_merged_into_one_chunk(): void
    {
        $content = "Paragraph one.\n\nParagraph two.";
        $chunks = $this->chunker->chunk($content, 400);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Paragraph one', $chunks[0]);
        $this->assertStringContainsString('Paragraph two', $chunks[0]);
    }

    public function test_long_content_splits_into_multiple_chunks(): void
    {
        // 5 paragraphs × 120 chars = 600 chars total, chunk size 200
        $paragraph = str_repeat('word ', 24); // ~120 chars
        $content = implode("\n\n", array_fill(0, 5, trim($paragraph)));

        $chunks = $this->chunker->chunk($content, 200);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(250, mb_strlen($chunk), 'Chunk exceeds max size');
        }
    }

    public function test_all_content_is_preserved(): void
    {
        $sentences = [
            'The company was founded in 2010.',
            'Our main product is a SaaS platform.',
            'We have offices in three countries.',
            'Customer support is available 24/7.',
            'Our pricing starts at $29 per month.',
        ];
        $content = implode(' ', $sentences);
        $chunks = $this->chunker->chunk($content, 80, 10);

        $combined = implode(' ', $chunks);
        foreach ($sentences as $sentence) {
            // Each sentence should appear in at least one chunk
            $found = false;
            foreach ($chunks as $chunk) {
                if (str_contains($chunk, substr($sentence, 0, 15))) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Sentence not found in any chunk: {$sentence}");
        }
    }

    public function test_single_paragraph_longer_than_max_is_split_by_sentences(): void
    {
        // One long paragraph with multiple sentences
        $content = 'First sentence of the paragraph. Second sentence here. Third sentence follows. Fourth and final sentence.';
        $chunks = $this->chunker->chunk($content, 60, 0);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk)); // some tolerance for overlap
        }
    }

    public function test_chunks_are_non_empty_strings(): void
    {
        $content = "Section A.\n\nSection B.\n\nSection C.\n\nSection D.";
        $chunks = $this->chunker->chunk($content, 20, 0);

        foreach ($chunks as $chunk) {
            $this->assertIsString($chunk);
            $this->assertNotEmpty(trim($chunk));
        }
    }

    public function test_returns_list_with_sequential_integer_keys(): void
    {
        $content = str_repeat("word ", 200);
        $chunks = $this->chunker->chunk($content, 100);

        $this->assertSame(array_keys($chunks), range(0, count($chunks) - 1));
    }

    public function test_unicode_content_is_handled_correctly(): void
    {
        $content = 'Olá, tudo bem? Sim, muito obrigado. Como posso ajudar? '
            .'Pode me dizer o preço? Claro, custa R$150,00. Perfeito, obrigado!';

        $chunks = $this->chunker->chunk($content, 50, 0);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertNotEmpty(trim($chunk));
        }
    }
}
