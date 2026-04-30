<?php

declare(strict_types=1);


namespace App\Services\Ai\Safety;

/**
 * Resultado de uma etapa individual do pipeline de segurança.
 *
 * Cada etapa recebe uma string de entrada e devolve:
 *  - `blocked`  — se esta etapa decide bloquear o fluxo
 *  - `output`   — string após transformações da etapa (ex.: PII redactado)
 *  - `reason`   — chave legível do motivo do bloqueio (null se não bloqueou)
 *  - `flags`    — lista de ocorrências detectadas (para auditoria)
 */
final class AiSafetyStageResult
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly string $output,
        public readonly ?string $reason = null,
        public readonly array $flags = [],
    ) {}
}
