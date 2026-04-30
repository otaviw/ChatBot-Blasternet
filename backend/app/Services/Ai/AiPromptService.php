<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiPromptHistory;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiPromptService
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     key:string,
     *     version:?string,
     *     environment:string,
     *     content:string,
     *     fallback_used:bool,
     *     source:string
     * }
     */
    public function resolvePrompt(
        string $templateKey,
        string $legacyFallbackText = '',
        ?int $companyId = null,
        ?int $userId = null,
        ?int $conversationId = null,
        ?string $providerRequested = null,
        ?string $providerResolved = null,
        array $metadata = []
    ): array {
        $environment = $this->resolveEnvironment();
        $resolved = $this->resolveTemplate($templateKey, $environment);

        $content = trim((string) ($resolved['content'] ?? ''));
        $source = 'template';
        if ($content === '') {
            $legacy = trim($legacyFallbackText);
            if ($legacy !== '') {
                $content = $legacy;
                $source = 'legacy_fallback';
                $resolved['fallback_used'] = true;
            }
        }

        $result = [
            'key' => (string) ($resolved['key'] ?? $templateKey),
            'version' => isset($resolved['version']) ? (string) $resolved['version'] : null,
            'environment' => $environment,
            'content' => $content,
            'fallback_used' => (bool) ($resolved['fallback_used'] ?? false),
            'source' => $source,
        ];

        $this->logResolution(
            $templateKey,
            $result,
            $companyId,
            $userId,
            $conversationId,
            $providerRequested,
            $providerResolved,
            $metadata
        );

        return $result;
    }

    private function resolveEnvironment(): string
    {
        $configured = mb_strtolower(trim((string) config('ai_prompts.environment', '')));
        if (in_array($configured, ['dev', 'prod'], true)) {
            return $configured;
        }

        $appEnv = mb_strtolower((string) app()->environment());

        return in_array($appEnv, ['local', 'development', 'testing'], true) ? 'dev' : 'prod';
    }

    /**
     * @return array{key:string,version:?string,content:string,fallback_used:bool}
     */
    private function resolveTemplate(string $templateKey, string $environment, int $depth = 0): array
    {
        $templates = config('ai_prompts.templates', []);
        if (! is_array($templates) || ! isset($templates[$templateKey]) || ! is_array($templates[$templateKey])) {
            return [
                'key' => $templateKey,
                'version' => null,
                'content' => '',
                'fallback_used' => false,
            ];
        }

        $template = $templates[$templateKey];
        $version = trim((string) ($template['version'] ?? ''));
        $environments = is_array($template['environments'] ?? null) ? $template['environments'] : [];
        $content = trim((string) ($environments[$environment] ?? ''));

        if ($content !== '') {
            return [
                'key' => $templateKey,
                'version' => $version !== '' ? $version : null,
                'content' => $content,
                'fallback_used' => false,
            ];
        }

        $fallbackKey = trim((string) ($template['fallback'] ?? ''));
        if ($fallbackKey === '' || $depth >= 4) {
            return [
                'key' => $templateKey,
                'version' => $version !== '' ? $version : null,
                'content' => '',
                'fallback_used' => false,
            ];
        }

        $fallback = $this->resolveTemplate($fallbackKey, $environment, $depth + 1);
        if (trim($fallback['content']) === '') {
            return [
                'key' => $templateKey,
                'version' => $version !== '' ? $version : null,
                'content' => '',
                'fallback_used' => false,
            ];
        }

        return [
            'key' => $fallback['key'],
            'version' => $fallback['version'],
            'content' => $fallback['content'],
            'fallback_used' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $metadata
     */
    private function logResolution(
        string $requestedTemplateKey,
        array $resolved,
        ?int $companyId,
        ?int $userId,
        ?int $conversationId,
        ?string $providerRequested,
        ?string $providerResolved,
        array $metadata
    ): void {
        $content = (string) ($resolved['content'] ?? '');
        $promptHash = hash('sha256', $content);

        if ((bool) config('ai_prompts.logs_enabled', true)) {
            Log::info('ai.prompt.resolved', [
                'requested_key' => $requestedTemplateKey,
                'resolved_key' => (string) ($resolved['key'] ?? $requestedTemplateKey),
                'version' => $resolved['version'] ?? null,
                'environment' => (string) ($resolved['environment'] ?? ''),
                'source' => (string) ($resolved['source'] ?? 'template'),
                'fallback_used' => (bool) ($resolved['fallback_used'] ?? false),
                'company_id' => $companyId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'provider_requested' => $providerRequested,
                'provider_resolved' => $providerResolved,
                'prompt_hash' => $promptHash,
            ]);
        }

        if (! (bool) config('ai_prompts.history_enabled', true)) {
            return;
        }

        try {
            AiPromptHistory::query()->create([
                'company_id' => $companyId && $companyId > 0 ? $companyId : null,
                'user_id' => $userId && $userId > 0 ? $userId : null,
                'conversation_id' => $conversationId && $conversationId > 0 ? $conversationId : null,
                'prompt_key' => (string) ($resolved['key'] ?? $requestedTemplateKey),
                'prompt_version' => $resolved['version'] ?? null,
                'prompt_environment' => (string) ($resolved['environment'] ?? 'prod'),
                'provider_requested' => $providerRequested !== null ? trim($providerRequested) : null,
                'provider_resolved' => $providerResolved !== null ? trim($providerResolved) : null,
                'fallback_used' => (bool) ($resolved['fallback_used'] ?? false),
                'system_prompt_hash' => $promptHash,
                'metadata' => $this->buildHistoryMetadata($content, $requestedTemplateKey, $metadata),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('ai.prompt.history_write_failed', [
                'requested_key' => $requestedTemplateKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildHistoryMetadata(string $content, string $requestedTemplateKey, array $metadata): array
    {
        $previewChars = max(0, (int) config('ai_prompts.history_preview_chars', 220));
        $preview = $previewChars > 0 ? mb_substr($content, 0, $previewChars) : '';

        return array_merge([
            'requested_key' => $requestedTemplateKey,
            'prompt_length' => mb_strlen($content),
            'prompt_preview' => $preview,
        ], $metadata);
    }
}

