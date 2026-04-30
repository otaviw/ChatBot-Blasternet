<?php

declare(strict_types=1);


namespace App\Services\Ai\Safety;

/**
 * Resultado agregado do pipeline de segurança de IA.
 *
 *  - `blocked`        — true se qualquer etapa bloqueou a entrada
 *  - `sanitizedInput` — entrada após todas as transformações (PII redactado)
 *  - `blockReason`    — chave do motivo do bloqueio, null se passou
 *  - `blockStage`     — nome da etapa que bloqueou, null se passou
 *  - `flags`          — todas as ocorrências detectadas pelas etapas
 */
final class AiSafetyResult
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly string $sanitizedInput,
        public readonly ?string $blockReason = null,
        public readonly ?string $blockStage = null,
        public readonly array $flags = [],
    ) {}

    public function passed(): bool
    {
        return ! $this->blocked;
    }
}
