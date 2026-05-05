<?php

declare(strict_types=1);


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
    public static function conversationCounters(int $companyId): string
    {
        return "counters:company:{$companyId}";
    }

    public static function companyTags(int $companyId): string
    {
        return "tags:company:{$companyId}";
    }

    public static function companyBotSettings(int $companyId): string
    {
        return "bot_settings:company:{$companyId}";
    }

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
