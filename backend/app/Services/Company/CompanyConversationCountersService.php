<?php

namespace App\Services\Company;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\CacheKeys;
use App\Support\ConversationStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CompanyConversationCountersService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForCompany(int $companyId): array
    {
        // Cache curto (30 s): equilibra consistência e custo de query.
        // Este método é chamado em dois pontos quentes:
        //   1. Polling do frontend (/conversas/contadores) — frequência alta
        //   2. MessageObserver.created() — dispara a cada mensagem recebida/enviada
        // Sem cache, cada mensagem nova causaria round-trips pesados ao banco.
        // Observers usam buildFreshForCompany() para publicar contadores atuais.
        return Cache::remember(
            CacheKeys::conversationCounters($companyId),
            now()->addSeconds(30),
            fn () => $this->query($companyId),
        );
    }

    /** @return array<string, mixed> */
    public function buildFreshForCompany(int $companyId): array
    {
        // Debounce: impede rebuilds repetidos em rajadas de mensagens.
        // Se o cache já foi reconstruído nos últimos 10 s, retorna o valor existente.
        // Garante no máximo 6 rebuilds/minuto por empresa, independente do volume de mensagens.
        $debounceKey = CacheKeys::conversationCounters($companyId) . ':debounce';

        if (Cache::has($debounceKey)) {
            return $this->buildForCompany($companyId);
        }

        Cache::put($debounceKey, true, now()->addSeconds(10));

        $this->forgetForCompany($companyId);

        return $this->buildForCompany($companyId);
    }

    public function forgetForCompany(int $companyId): void
    {
        Cache::forget(CacheKeys::conversationCounters($companyId));
    }

    /** @return array<string, mixed> */
    private function query(int $companyId): array
    {
        $lastMessageDirectionSubquery = Message::query()
            ->select('direction')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        $openConversationsWithLastDirection = Conversation::withoutGlobalScopes()
            ->where('conversations.company_id', $companyId)
            ->where('conversations.status', '!=', ConversationStatus::CLOSED)
            ->select(['conversations.id', 'conversations.current_area_id'])
            ->addSelect([
                'last_message_direction' => $lastMessageDirectionSubquery,
            ]);

        $rows = DB::query()
            ->fromSub($openConversationsWithLastDirection, 'open_conversations')
            ->leftJoin('areas', 'areas.id', '=', 'open_conversations.current_area_id')
            ->selectRaw('
                open_conversations.current_area_id as area_id,
                areas.name as area_nome,
                COUNT(*) as total_abertas,
                SUM(CASE WHEN open_conversations.last_message_direction = ? THEN 1 ELSE 0 END) as total_sem_resposta
            ', ['in'])
            ->groupBy('open_conversations.current_area_id', 'areas.name')
            ->get();

        $porArea = [];
        $semAreaTotalAbertas = 0;
        $semAreaTotalSemResposta = 0;
        $totalAbertas = 0;

        foreach ($rows as $row) {
            $areaId = $row->area_id ? (int) $row->area_id : null;
            $totalAbertasRow = (int) ($row->total_abertas ?? 0);
            $totalSemRespostaRow = (int) ($row->total_sem_resposta ?? 0);
            $totalAbertas += $totalAbertasRow;

            if ($areaId === null) {
                $semAreaTotalAbertas = $totalAbertasRow;
                $semAreaTotalSemResposta = $totalSemRespostaRow;
                continue;
            }

            $porArea[] = [
                'area_id' => $areaId,
                'area_nome' => (string) ($row->area_nome ?? ''),
                'total_abertas' => $totalAbertasRow,
                'total_sem_resposta' => $totalSemRespostaRow,
            ];
        }

        usort($porArea, fn (array $left, array $right): int => strcmp(
            mb_strtolower((string) ($left['area_nome'] ?? '')),
            mb_strtolower((string) ($right['area_nome'] ?? ''))
        ));

        return [
            'por_area' => $porArea,
            'sem_area' => [
                'total_abertas' => $semAreaTotalAbertas,
                'total_sem_resposta' => $semAreaTotalSemResposta,
            ],
            'total_abertas' => $totalAbertas,
        ];
    }
}
