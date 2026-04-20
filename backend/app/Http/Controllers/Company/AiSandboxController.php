<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\TestAiSandboxRequest;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Ai\AiMetricsService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Rag\AiKnowledgeRetrieverService;
use Illuminate\Http\JsonResponse;

/**
 * Sandbox endpoint — lets company admins test the configured AI provider
 * directly from the settings panel without going through a real conversation.
 *
 * Throttled at 20 req/min (see routes).
 */
class AiSandboxController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccessService,
        private readonly AiProviderResolver $providerResolver,
        private readonly AiKnowledgeRetrieverService $retriever,
        private readonly AiMetricsService $metricsService,
    ) {}

    public function test(TestAiSandboxRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        if (! $this->aiAccessService->canManageAi($user)) {
            return response()->json([
                'authenticated' => true,
                'message'       => 'Acesso restrito ao administrador da empresa.',
            ], 403);
        }

        $settings = $this->aiAccessService->resolveCompanySettings($user);
        if (! $settings instanceof CompanyBotSetting) {
            return response()->json(['message' => 'IA não está configurada para esta empresa.'], 422);
        }

        $validated = $request->validated();

        $message    = trim((string) $validated['message']);
        $includeRag = (bool) ($validated['include_rag'] ?? false);
        $companyId  = (int) $user->company_id;

        [$providerName, $modelName] = $this->resolveProviderAndModel($settings);

        // Build system prompt
        $systemParts = array_filter([
            trim((string) config('ai.system_prompt', '')),
            trim((string) ($settings->ai_system_prompt ?? '')),
        ]);

        // Retrieve RAG chunks if requested
        $ragChunks = [];
        if ($includeRag && $companyId > 0) {
            $topK      = (int) config('ai.rag.top_k', 3);
            $ragChunks = $this->retriever->retrieve($companyId, $message, $topK);

            if ($ragChunks !== []) {
                $lines = ['Base de conhecimento da empresa:'];
                $n     = 1;
                foreach ($ragChunks as $chunk) {
                    $title   = trim((string) ($chunk['title']   ?? ''));
                    $content = trim((string) ($chunk['content'] ?? ''));
                    if ($content === '') {
                        continue;
                    }
                    $label   = $title !== '' ? $title : 'Sem titulo';
                    $lines[] = "{$n}. {$label}: {$content}";
                    $n++;
                }
                if (count($lines) > 1) {
                    $systemParts[] = implode(PHP_EOL, $lines);
                }
            }
        }

        $contextMessages = [];
        if ($systemParts !== []) {
            $contextMessages[] = [
                'role'    => AiMessage::ROLE_SYSTEM,
                'content' => implode(PHP_EOL.PHP_EOL, $systemParts),
            ];
        }
        $contextMessages[] = ['role' => AiMessage::ROLE_USER, 'content' => $message];

        $provider = $this->providerResolver->resolve($providerName);
        $options  = [
            'company_id'          => $companyId,
            'model'               => $modelName,
            'temperature'         => $settings->ai_temperature ?? null,
            'max_response_tokens' => $settings->ai_max_response_tokens ?? null,
            'request_timeout_ms'  => (int) config('ai.request_timeout_ms', 30000),
        ];

        $measured = $this->metricsService->measure(fn () => $provider->reply($contextMessages, $options));

        if ($measured['exception'] !== null) {
            return response()->json([
                'message' => 'Falha ao obter resposta da IA: ' . $measured['exception']->getMessage(),
            ], 422);
        }

        $providerResult = is_array($measured['result']) ? $measured['result'] : [];

        if (! (bool) ($providerResult['ok'] ?? false)) {
            $meta    = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : [];
            $errMsg  = trim((string) ($meta['message'] ?? $providerResult['error'] ?? 'Falha ao obter resposta da IA.'));

            return response()->json(['message' => $errMsg], 422);
        }

        $tokensUsed = $this->extractTokensUsed($providerResult);
        $usedRag    = $includeRag && $ragChunks !== [];

        return response()->json([
            'ok'               => true,
            'response'         => trim((string) ($providerResult['text'] ?? '')),
            'confidence_score' => $this->calculateConfidence($usedRag, $ragChunks),
            'rag_chunks_used'  => array_map(static fn (array $c) => [
                'title'   => $c['title'],
                'content' => mb_substr((string) $c['content'], 0, 300),
                'score'   => isset($c['score']) ? round((float) $c['score'], 4) : null,
            ], $ragChunks),
            'tokens_used'      => $tokensUsed,
            'provider'         => $providerName,
            'model'            => $modelName,
        ]);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveProviderAndModel(CompanyBotSetting $settings): array
    {
        $defaultProvider = $this->providerResolver->resolveProviderName(
            $this->providerResolver->defaultProviderName()
        );

        $companyProvider = mb_strtolower(trim((string) ($settings->ai_provider ?? '')));
        $providerName    = ($companyProvider !== '' && $this->providerResolver->supports($companyProvider))
            ? $companyProvider
            : $defaultProvider;

        $companyModel = trim((string) ($settings->ai_model ?? ''));
        $globalModel  = trim((string) config('ai.model', ''));
        $modelName    = $companyModel !== '' ? $companyModel : ($globalModel !== '' ? $globalModel : null);

        return [$providerName, $modelName];
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function extractTokensUsed(array $providerResult): ?int
    {
        $meta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : [];

        foreach ([
            $providerResult['tokens_used'] ?? null,
            $meta['tokens_used'] ?? null,
            data_get($meta, 'usage.total_tokens'),
            data_get($meta, 'usage.tokens'),
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate >= 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array{score:float|null}>  $ragChunks
     */
    private function calculateConfidence(bool $usedRag, array $ragChunks): float
    {
        if (! $usedRag) {
            return 0.5;
        }

        $bestScore = 0.0;
        foreach ($ragChunks as $chunk) {
            $score = (float) ($chunk['score'] ?? 0.0);
            if ($score > $bestScore) {
                $bestScore = $score;
            }
        }

        if ($bestScore === 0.0) {
            return 0.65;
        }

        return round(min(0.97, max(0.80, 0.80 + ($bestScore - 0.3) * (0.17 / 0.7))), 2);
    }
}
