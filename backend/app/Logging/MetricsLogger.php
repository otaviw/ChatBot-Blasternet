<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Helper centralizado para log de métricas.
 *
 * Todas as entradas usam o campo "message" como evento fixo (ex: "http_request",
 * "message_count") para que ferramentas de log (Loki, CloudWatch, Datadog) consigam
 * filtrar e agregar por event= sem precisar parsear texto livre.
 *
 * O nível de log (info/warning/error) permite separar métricas normais de anomalias
 * usando os mesmos filtros de nível já existentes na infraestrutura.
 */
class MetricsLogger
{
    // -------------------------------------------------------------------------
    // HTTP — tempo de resposta + erros por endpoint
    // -------------------------------------------------------------------------

    /**
     * Registra uma request HTTP concluída com duração e status.
     *
     * @param array{
     *   method:      string,
     *   path:        string,
     *   status:      int,
     *   duration_ms: float,
     *   user_id:     int|null,
     *   company_id:  int|null,
     * } $data
     */
    public static function httpRequest(array $data): void
    {
        $status = $data['status'];

        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        Log::$level('http_request', $data);
    }

    // -------------------------------------------------------------------------
    // Mensagens — contagem por direção e tipo
    // -------------------------------------------------------------------------

    /**
     * Registra a criação de uma mensagem (inbound ou outbound).
     *
     * @param string   $direction     'in' | 'out'
     * @param string   $contentType   'text' | 'image' | 'audio' | 'video' | 'document' | 'location'
     * @param string   $senderType    'user' | 'bot' | 'human' (quem gerou a mensagem)
     * @param int      $conversationId
     * @param int|null $companyId
     */
    public static function message(
        string $direction,
        string $contentType,
        string $senderType,
        int $conversationId,
        ?int $companyId,
    ): void {
        Log::info('message_count', [
            'direction'       => $direction,
            'content_type'    => $contentType,
            'sender_type'     => $senderType,
            'conversation_id' => $conversationId,
            'company_id'      => $companyId,
        ]);
    }
}
