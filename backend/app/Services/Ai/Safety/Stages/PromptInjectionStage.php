<?php

declare(strict_types=1);


namespace App\Services\Ai\Safety\Stages;

use App\Services\Ai\Safety\AiSafetyStage;
use App\Services\Ai\Safety\AiSafetyStageResult;

/**
 * Etapa de detecção de prompt injection.
 *
 * Detecta tentativas de manipular o comportamento do modelo via entrada do usuário.
 * Bloqueia imediatamente ao detectar o primeiro padrão positivo.
 *
 * Critério de design:
 *  - Baixo risco de falso positivo: padrões são frases completas, não palavras isoladas
 *  - Extensível: adicionar novos padrões em PATTERNS sem alterar a lógica
 *  - Retorna a chave do padrão no motivo para facilitar auditoria
 *
 * Para adicionar um provider de moderação real no futuro:
 *  - Substituir ou complementar este stage por um que chame a API do provider
 *  - Manter a mesma interface AiSafetyStage
 */
class PromptInjectionStage implements AiSafetyStage
{
    /**
     * Padrões nomeados de prompt injection (case-insensitive).
     *
     * Chave = identificador do padrão usado em logs/auditoria.
     * Valor = expressão regular.
     *
     * @var array<string, non-empty-string>
     */
    private const PATTERNS = [
        // Instruções de ignorar/sobrescrever contexto
        'ignore_instructions'    => '/\bignore\s+(all\s+|previous\s+|above\s+|prior\s+)?instructions?\b/i',
        'disregard_context'      => '/\bdisregard\b.{0,20}(instructions?|context|rules)\b/i',
        'forget_instructions'    => '/\bforget\s+(everything|all\s+previous|your\s+instructions|prior\s+instructions)\b/i',
        'new_instructions'       => '/\bnew\s+instructions?\s*:/i',

        // Tentativas de extrair o system prompt
        'reveal_prompt'          => '/\breveal\s+(your\s+|the\s+)?(system\s+)?prompt\b/i',
        'print_prompt'           => '/\bprint\s+(your\s+|the\s+)?(system\s+)?prompt\b/i',
        'show_prompt'            => '/\bshow\s+(me\s+)?(your\s+|the\s+)?(system\s+)?prompt\b/i',
        'what_is_your_prompt'    => '/\bwhat\s+(is|are)\s+(your\s+)?(system\s+)?prompt\b/i',

        // Tentativas de fazer o modelo assumir nova identidade
        'act_as'                 => '/\bact\s+as\s+(if\s+you\s+are|a\s+|an\s+).{0,60}(without|no)\s+(restrictions?|rules?|guidelines?|safety|filters?)\b/i',
        'you_are_now_unrestricted'=> '/\byou\s+are\s+now\s+(a\s+|an\s+)?.{0,40}(without|no)\s+(restrictions?|rules?|safety)\b/i',
        'pretend_no_restrictions'=> '/\bpretend\s+(you\s+are|to\s+be|you\'?re)\s+(a\s+|an\s+)?.{0,60}(without|no)\s+(restrictions?|rules?|filters?|safety)\b/i',

        // Jailbreak explícito
        'jailbreak'              => '/\bjailbreak\b/i',
        'dan_mode'               => '/\bDAN\s+mode\b/i',
        'developer_mode'         => '/\bdeveloper\s+mode\s+(enabled|on|activated)\b/i',

        // Bypass de restrições
        'bypass_safety'          => '/\bbypass\s+(your\s+|all\s+)?(safety|restrictions?|rules?|guidelines?|filters?)\b/i',
        'override_system'        => '/\boverride\s+(the\s+)?(system|safety|context|prompt|instructions?)\b/i',

        // Injeção via separador de contexto
        'context_separator'      => '/\n\s*---+\s*\n.{0,30}(system|instructions?|prompt)\s*:/i',
        'role_injection'         => '/^\s*(system|user|assistant)\s*:\s*(ignore|forget|you\s+are\s+now)/im',
    ];

    public function run(string $input): AiSafetyStageResult
    {
        foreach (self::PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $input) === 1) {
                return new AiSafetyStageResult(
                    blocked: true,
                    output: $input,
                    reason: "prompt_injection:{$name}",
                    flags: ["injection_detected:{$name}"],
                );
            }
        }

        return new AiSafetyStageResult(
            blocked: false,
            output: $input,
        );
    }

    public function name(): string
    {
        return 'prompt_injection';
    }
}
