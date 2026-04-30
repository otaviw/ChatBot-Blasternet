<?php

declare(strict_types=1);


namespace App\Services\Ai\Safety\Stages;

use App\Services\Ai\Safety\AiSafetyStage;
use App\Services\Ai\Safety\AiSafetyStageResult;

/**
 * Etapa de moderação de entrada por palavras/frases proibidas.
 *
 * Configuração via `config('ai.safety.forbidden_words')`:
 *  - Array de strings, ou string separada por vírgula
 *  - Vazio por padrão (sem falsos positivos out-of-the-box)
 *  - Extensível sem alterar código: basta adicionar à config/env
 *
 * Exemplo em .env:
 *   AI_SAFETY_FORBIDDEN_WORDS="palavra1,frase proibida,outra"
 *
 * Exemplo em config/ai.php:
 *   'safety' => ['forbidden_words' => explode(',', env('AI_SAFETY_FORBIDDEN_WORDS', ''))]
 *
 * Checagem é case-insensitive e usa correspondência de substring.
 * Para checagens mais sofisticadas (regex, score), substituir ou complementar
 * este stage por um que chame um provider de moderação (OpenAI Moderation API, etc.).
 */
class InputModerationStage implements AiSafetyStage
{
    /** @var list<string> */
    private readonly array $forbiddenWords;

    public function __construct()
    {
        $this->forbiddenWords = $this->loadForbiddenWords();
    }

    public function run(string $input): AiSafetyStageResult
    {
        if ($this->forbiddenWords === []) {
            return new AiSafetyStageResult(blocked: false, output: $input);
        }

        $normalized = mb_strtolower($input);

        foreach ($this->forbiddenWords as $word) {
            if (str_contains($normalized, $word)) {
                return new AiSafetyStageResult(
                    blocked: true,
                    output: $input,
                    reason: 'forbidden_content',
                    flags: ['forbidden_word_detected'],
                );
            }
        }

        return new AiSafetyStageResult(blocked: false, output: $input);
    }

    public function name(): string
    {
        return 'input_moderation';
    }

    /** @return list<string> */
    private function loadForbiddenWords(): array
    {
        $raw = config('ai.safety.forbidden_words', []);

        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        return array_values(
            array_filter(
                array_map(
                    fn ($w) => mb_strtolower(trim((string) $w)),
                    (array) $raw
                )
            )
        );
    }
}
