<?php

namespace App\Services\Ai\Safety;

/**
 * Contrato de uma etapa no pipeline de segurança de IA.
 *
 * Cada etapa recebe a string já processada pelas etapas anteriores
 * e devolve um AiSafetyStageResult com:
 *   - o output (possivelmente transformado, ex.: PII redactado)
 *   - se deve bloquear, e por quê
 *
 * Ordem de execução recomendada:
 *   1. PiiRedactionStage    — sanitiza antes de verificar conteúdo
 *   2. PromptInjectionStage — detecta tentativas de manipulação
 *   3. InputModerationStage — verifica palavras proibidas configuráveis
 */
interface AiSafetyStage
{
    public function run(string $input): AiSafetyStageResult;

    /** Identificador único da etapa para logs e auditoria. */
    public function name(): string;
}
