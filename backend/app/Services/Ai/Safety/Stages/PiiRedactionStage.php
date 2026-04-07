<?php

namespace App\Services\Ai\Safety\Stages;

use App\Services\Ai\Safety\AiSafetyStage;
use App\Services\Ai\Safety\AiSafetyStageResult;

/**
 * Etapa de redação de PII (Personally Identifiable Information).
 *
 * Nunca bloqueia — apenas substitui ocorrências detectadas por marcadores,
 * garantindo que dados pessoais não cheguem ao provider de IA.
 *
 * Padrões tratados:
 *  - E-mail
 *  - CPF  (com ou sem pontuação)
 *  - CNPJ (com ou sem pontuação)
 *  - Telefone BR (celular e fixo, com e sem DDI/DDD)
 *
 * Boas práticas:
 *  - Executar PRIMEIRO no pipeline (antes de checagens de conteúdo)
 *  - Não alterar o banco de dados — só o texto enviado ao provider
 */
class PiiRedactionStage implements AiSafetyStage
{
    /**
     * Padrões indexados por tipo de PII.
     * Cada entrada: ['pattern' => regex, 'replacement' => string]
     *
     * @var array<string, array{pattern: non-empty-string, replacement: non-empty-string}>
     */
    private const PATTERNS = [
        'email' => [
            'pattern' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u',
            'replacement' => '[EMAIL]',
        ],
        // CNPJ antes de CPF para evitar match parcial (CNPJ tem mais dígitos)
        'cnpj' => [
            'pattern' => '/\b\d{2}\.?\d{3}\.?\d{3}\/?0{0,1}\d{3,4}[-]?\d{2}\b/',
            'replacement' => '[CNPJ]',
        ],
        'cpf' => [
            'pattern' => '/\b\d{3}\.?\d{3}\.?\d{3}[\-\s]?\d{2}\b/',
            'replacement' => '[CPF]',
        ],
        'phone_br' => [
            // Aceita: +55 11 9 1234-5678 | (11)91234-5678 | 11 91234-5678 | 9 1234-5678
            'pattern' => '/(?:\+55\s?)?(?:\(?\d{2}\)?\s?)?(?:9\s?)?\d{4}[\s\-]?\d{4}\b/',
            'replacement' => '[TELEFONE]',
        ],
    ];

    public function run(string $input): AiSafetyStageResult
    {
        $output = $input;
        $flags = [];

        foreach (self::PATTERNS as $type => $config) {
            $count = preg_match_all($config['pattern'], $output);
            if (is_int($count) && $count > 0) {
                $flags[] = "pii_{$type}_redacted";
                $output = (string) preg_replace($config['pattern'], $config['replacement'], $output);
            }
        }

        return new AiSafetyStageResult(
            blocked: false,
            output: $output,
            reason: null,
            flags: $flags,
        );
    }

    public function name(): string
    {
        return 'pii_redaction';
    }
}
