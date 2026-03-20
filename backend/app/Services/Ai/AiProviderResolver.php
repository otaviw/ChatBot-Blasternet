<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\TestAiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

class AiProviderResolver
{
    /**
     * @var array<string, class-string<AiProvider>>
     */
    private const PROVIDERS = [
        'test' => TestAiProvider::class,
        'null' => NullAiProvider::class,
    ];

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function supports(string $providerName): bool
    {
        $normalized = mb_strtolower(trim($providerName));

        return in_array($normalized, $this->supportedProviders(), true);
    }

    public function resolve(?string $providerName = null): AiProvider
    {
        $normalizedProvider = $this->normalizeProviderName($providerName);
        $providerClass = self::PROVIDERS[$normalizedProvider] ?? null;

        if ($providerClass === null) {
            return $this->resolveInvalidProvider($normalizedProvider);
        }

        return $this->makeProvider($providerClass);
    }

    private function resolveInvalidProvider(string $providerName): AiProvider
    {
        Log::warning('ai.provider.invalid', [
            'provider' => $providerName,
            'fallback' => 'null',
        ]);

        return $this->makeProvider(self::PROVIDERS['null']);
    }

    private function normalizeProviderName(?string $providerName): string
    {
        $normalizedProvider = mb_strtolower(trim((string) ($providerName ?? config('ai.provider', 'null'))));

        return $normalizedProvider !== '' ? $normalizedProvider : 'null';
    }

    /**
     * @param  class-string<AiProvider>  $providerClass
     */
    private function makeProvider(string $providerClass): AiProvider
    {
        return $this->container->make($providerClass);
    }
}
