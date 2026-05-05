<?php

declare(strict_types=1);


namespace App\Services\Ai\Rag;

/**
 * Splits knowledge item content into overlapping chunks suitable for embedding.
 *
 * Strategy (cascading):
 *  1. Split by blank lines (paragraphs) — respects natural structure
 *  2. If a paragraph is too large, split by sentence boundaries
 *  3. If a sentence is still too large, hard-split by character count
 *
 * Adjacent chunks share an $overlap-char tail to preserve cross-boundary context.
 */
class AiKnowledgeChunkerService
{
    /**
     * Split content into chunks.
     *
     * @param  string  $content  Raw knowledge item content
     * @param  int  $maxSize   Max characters per chunk (default from config or 400)
     * @param  int  $overlap   Characters to repeat at start of next chunk (default 50)
     * @return list<string>
     */
    public function chunk(string $content, int $maxSize = 400, int $overlap = 50): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (mb_strlen($content) <= $maxSize) {
            return [$content];
        }

        $paragraphs = $this->splitByBlankLines($content);

        return $this->aggregateIntoChunks($paragraphs, $maxSize, $overlap, depth: 0);
    }

    /**
     * @return list<string>
     */
    private function aggregateIntoChunks(array $parts, int $maxSize, int $overlap, int $depth): array
    {
        $chunks = [];
        $current = '';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $separator = $current !== '' ? "\n\n" : '';
            if (mb_strlen($current.$separator.$part) <= $maxSize) {
                $current .= $separator.$part;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            if (mb_strlen($part) > $maxSize && $depth < 2) {
                $subParts = $this->subdivide($part, $depth);
                $subChunks = $this->aggregateIntoChunks($subParts, $maxSize, $overlap, $depth + 1);
                foreach ($subChunks as $sub) {
                    $chunks[] = $sub;
                }
                $current = '';
            } else {
                $current = $this->applyOverlap($chunks, $overlap).$part;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks)));
    }

    /**
     * Subdivide a text block that is too large.
     * depth=0 → split by sentence; depth=1 → hard-split by chars.
     *
     * @return list<string>
     */
    private function subdivide(string $text, int $depth): array
    {
        if ($depth === 0) {
            $parts = preg_split('/(?<=[.!?:])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts) && count($parts) > 1) {
                return array_values(array_filter(array_map('trim', $parts)));
            }
        }

        return $this->hardSplitByWords($text, 400);
    }

    /**
     * Split at word boundaries, targeting chunks of $approxSize chars.
     *
     * @return list<string>
     */
    private function hardSplitByWords(string $text, int $approxSize): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($words)) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current.' '.$word) <= $approxSize) {
                $current .= ' '.$word;
            } else {
                $chunks[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Extract the overlap tail from the last chunk to prepend to the next chunk.
     */
    private function applyOverlap(array $chunks, int $overlap): string
    {
        if ($overlap <= 0 || $chunks === []) {
            return '';
        }

        $last = end($chunks);
        $len = mb_strlen($last);

        if ($len <= $overlap) {
            return $last.' ';
        }

        $tail = mb_substr($last, $len - $overlap);
        $firstSpace = strpos($tail, ' ');

        return ($firstSpace !== false ? mb_substr($tail, $firstSpace + 1) : $tail).' ';
    }

    /**
     * @return list<string>
     */
    private function splitByBlankLines(string $text): array
    {
        $parts = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts)
            ? array_values(array_filter(array_map('trim', $parts)))
            : [trim($text)];
    }
}
