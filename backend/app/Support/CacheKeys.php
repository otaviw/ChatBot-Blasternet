<?php

namespace App\Support;

/**
 * Gerador centralizado de chaves de cache.
 *
 * Manter as chaves aqui evita strings mágicas espalhadas pelo código e garante
 * que múltiplos consumidores do mesmo dado usem exatamente a mesma chave —
 * o que é necessário tanto para hits de cache quanto para invalidação correta.
 *
 * Nomenclatura: snake_case hierárquico separado por ":" (padrão Redis).
 */
class CacheKeys
{
    // -------------------------------------------------------------------------
    // Counters de conversa por empresa
    // TTL recomendado: 30 s  (veja CompanyConversationCountersService)
    // Invalidação: não é feita explicitamente — TTL curto já garante
    //              consistência suficiente para o inbox e para o realtime.
    // -------------------------------------------------------------------------
    public static function conversationCounters(int $companyId): string
    {
        return "counters:company:{$companyId}";
    }

    // -------------------------------------------------------------------------
    // Tags da empresa (lista para filtros / dropdowns)
    // TTL recomendado: 10 min  (veja ListCompanyConversationsAction, ConversationTagController)
    // Invalidação: explícita em store / update / destroy de tags
    // -------------------------------------------------------------------------
    public static function companyTags(int $companyId): string
    {
        return "tags:company:{$companyId}";
    }

    // -------------------------------------------------------------------------
    // Métricas de IA (analytics de AiMetricsController)
    // TTL recomendado: 5 min  (dados históricos, stale aceitável)
    // Invalidação: não é feita explicitamente — dados analíticos podem ser
    //              levemente defasados sem impacto operacional.
    // -------------------------------------------------------------------------
    public static function aiMetrics(
        string $companyScope,   // "all" | "42" (ID stringificado)
        string $dateFrom,       // "2026-03-01"
        string $dateTo,         // "2026-04-15"
        string $provider,       // "" = sem filtro
        string $feature,        // "" = sem filtro
    ): string {
        $prov = $provider !== '' ? $provider : 'any';
        $feat = $feature  !== '' ? $feature  : 'any';

        return "ai_metrics:{$companyScope}:{$dateFrom}:{$dateTo}:{$prov}:{$feat}";
    }
}
