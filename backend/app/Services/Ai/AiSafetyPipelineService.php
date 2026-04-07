<?php

namespace App\Services\Ai;

use App\Services\Ai\Safety\AiSafetyResult;
use App\Services\Ai\Safety\AiSafetyStage;
use App\Services\Ai\Safety\Stages\InputModerationStage;
use App\Services\Ai\Safety\Stages\PiiRedactionStage;
use App\Services\Ai\Safety\Stages\PromptInjectionStage;

/**
 * Pipeline de segurança para entradas/saídas de IA.
 *
 * Etapas (em ordem de execução):
 *  1. PiiRedactionStage    — remove PII antes de qualquer verificação de conteúdo
 *  2. PromptInjectionStage — bloqueia tentativas de manipulação do modelo
 *  3. InputModerationStage — bloqueia palavras/frases proibidas (configurável)
 *
 * Como usar:
 *   $result = $pipeline->run($userInput);
 *   if ($result->blocked) {
 *       // registrar auditoria e retornar mensagem segura
 *   }
 *   // usar $result->sanitizedInput como entrada para o provider
 *
 * Para adicionar uma nova etapa:
 *   1. Criar classe que implemente AiSafetyStage
 *   2. Injetá-la no construtor e adicioná-la ao array $stages
 *   3. Definir posição na ordem (ex.: depois de PII, antes de injection)
 */
class AiSafetyPipelineService
{
    /** @var list<AiSafetyStage> */
    private readonly array $stages;

    public function __construct(
        PiiRedactionStage $piiRedaction,
        PromptInjectionStage $promptInjection,
        InputModerationStage $inputModeration,
    ) {
        // Ordem deliberada: redact PII primeiro, depois checar conteúdo
        $this->stages = [$piiRedaction, $promptInjection, $inputModeration];
    }

    /**
     * Executa o pipeline completo sobre a entrada do usuário.
     *
     * O output de cada etapa é a entrada da próxima (chain of responsibility).
     * Se qualquer etapa bloquear, o pipeline para e retorna imediatamente.
     * As flags de TODAS as etapas executadas são acumuladas no resultado.
     */
    public function run(string $input): AiSafetyResult
    {
        $current = $input;
        $allFlags = [];

        foreach ($this->stages as $stage) {
            $stageResult = $stage->run($current);

            // Acumula flags mesmo se não bloquear (ex.: PII detectado mas não bloqueado)
            foreach ($stageResult->flags as $flag) {
                $allFlags[] = $flag;
            }

            // O output sanitizado passa para a próxima etapa
            $current = $stageResult->output;

            if ($stageResult->blocked) {
                return new AiSafetyResult(
                    blocked: true,
                    sanitizedInput: $current,
                    blockReason: $stageResult->reason,
                    blockStage: $stage->name(),
                    flags: $allFlags,
                );
            }
        }

        return new AiSafetyResult(
            blocked: false,
            sanitizedInput: $current,
            blockReason: null,
            blockStage: null,
            flags: $allFlags,
        );
    }

    /**
     * Aplica apenas redação de PII sobre um texto.
     *
     * Útil para sanitizar mensagens individuais em contextos já montados
     * (ex.: histórico de conversa antes de enviar ao provider).
     */
    public function redactPii(string $input): string
    {
        foreach ($this->stages as $stage) {
            if ($stage instanceof PiiRedactionStage) {
                return $stage->run($input)->output;
            }
        }

        return $input;
    }

    /**
     * Redacta PII de todos os turnos de usuário em um array de mensagens de contexto.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public function redactContextMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            if (($message['role'] ?? '') === 'user') {
                $message['content'] = $this->redactPii((string) ($message['content'] ?? ''));
            }

            return $message;
        }, $messages);
    }
}
