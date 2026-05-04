<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\ChatbotAiSuggestionResultNormalizer;
use PHPUnit\Framework\TestCase;

class ChatbotAiSuggestionResultNormalizerTest extends TestCase
{
    public function test_normalizer_accepts_plain_string_suggestion(): void
    {
        $result = ChatbotAiSuggestionResultNormalizer::toReplyText('  Resposta IA  ');

        $this->assertSame('Resposta IA', $result);
    }

    public function test_normalizer_extracts_text_from_array_payload(): void
    {
        $result = ChatbotAiSuggestionResultNormalizer::toReplyText([
            'suggestion' => '  Texto vindo do array  ',
            'confidence_score' => 0.91,
        ]);

        $this->assertSame('Texto vindo do array', $result);
    }
}
