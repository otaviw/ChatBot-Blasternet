<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\AnthropicAiProvider;
use App\Services\Ai\Providers\FailoverAiProvider;
use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OllamaAiProvider;
use App\Services\Ai\Providers\TestAiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

class AiProviderResolver
{
    /**
     * @var array<string, class-string<AiProvider>>
     */
    private const DEFAULT_PROVIDER_CLASSES = [
        'anthropic' => AnthropicAiProvider::class,
        'ollama' => OllamaAiProvider::class,
        'test' => TestAiProvider::class,
        'null' => NullAiProvider::class,
    ];

    private const FALLBACK_PROVIDER = 'null';

    /**
     * @var array<string, class-string<AiProvider>>|null
     */
    private ?array $providerClasses = null;

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return array_keys($this->providerClasses());
    }

    public function supports(string $providerName): bool
    {
        $normalized = $this->normalizeProviderName($providerName);

        return $normalized !== '' && array_key_exists($normalized, $this->providerClasses());
    }

    public function resolve(?string $providerName = null): AiProvider
    {
        $requestedProviderName = $this->normalizeProviderName((string) ($providerName ?? $this->defaultProviderName()));
        $resolvedProviderName = $this->resolveProviderName($requestedProviderName);

        if ($requestedProviderName !== '' && $requestedProviderName !== $resolvedProviderName && ! $this->supports($requestedProviderName)) {
            Log::warning('ai.provider.invalid', [
                'provider' => $requestedProviderName,
                'fallback' => $resolvedProviderName,
                'supported_providers' => $this->supportedProviders(),
            ]);
        }

        $providerClass = $this->providerClasses()[$resolvedProviderName] ?? null;

        if ($providerClass === null) {
            return $this->resolveInvalidProvider($resolvedProviderName);
        }

        $provider = $this->makeProvider($providerClass, $resolvedProviderName);
        $fallbackProviderName = $this->fallbackProviderName();

        // Fallback automatico em runtime e aplicado apenas quando o fallback
        // configurado e diferente de "null" para preservar comportamento legado.
        if (
            $fallbackProviderName !== ''
            && $fallbackProviderName !== self::FALLBACK_PROVIDER
            && $fallbackProviderName !== $resolvedProviderName
            && $this->supports($fallbackProviderName)
        ) {
            $fallbackClass = $this->providerClasses()[$fallbackProviderName] ?? null;
            if ($fallbackClass !== null) {
                $fallbackProvider = $this->makeProvider($fallbackClass, $fallbackProviderName);

                return new FailoverAiProvider(
                    $resolvedProviderName,
                    $provider,
                    $fallbackProviderName,
                    $fallbackProvider
                );
            }
        }

        return $provider;
    }

    public function defaultProviderName(): string
    {
        $configured = (string) config('ai.provider', config('ai.default_provider', self::FALLBACK_PROVIDER));
        $normalized = $this->normalizeProviderName($configured);

        return $normalized !== '' ? $normalized : self::FALLBACK_PROVIDER;
    }

    public function fallbackProviderName(): string
    {
        $configured = $this->normalizeProviderName((string) config('ai.fallback_provider', self::FALLBACK_PROVIDER));

        if ($configured !== '' && $this->supports($configured)) {
            return $configured;
        }

        return self::FALLBACK_PROVIDER;
    }

    public function resolveProviderName(?string $providerName = null, ?string $fallbackProviderName = null): string
    {
        $requestedProvider = $this->normalizeProviderName((string) ($providerName ?? ''));
        if ($requestedProvider !== '' && $this->supports($requestedProvider)) {
            return $requestedProvider;
        }

        $requestedFallback = $this->normalizeProviderName((string) ($fallbackProviderName ?? $this->defaultProviderName()));
        if ($requestedFallback !== '' && $this->supports($requestedFallback)) {
            return $requestedFallback;
        }

        return $this->fallbackProviderName();
    }

    private function resolveInvalidProvider(string $providerName): AiProvider
    {
        $fallbackProvider = $this->fallbackProviderName();
        $providers = $this->supportedProviders();

        Log::warning('ai.provider.invalid', [
            'provider' => $providerName,
            'fallback' => $fallbackProvider,
            'supported_providers' => $providers,
        ]);

        $fallbackClass = $this->providerClasses()[$fallbackProvider] ?? self::DEFAULT_PROVIDER_CLASSES[self::FALLBACK_PROVIDER];

        return $this->makeProvider($fallbackClass, $fallbackProvider);
    }

    private function normalizeProviderName(string $providerName): string
    {
        return mb_strtolower(trim($providerName));
    }

    /**
     * @return array<string, class-string<AiProvider>>
     */
    private function providerClasses(): array
    {
        if (is_array($this->providerClasses)) {
            return $this->providerClasses;
        }

        $configured = config('ai.provider_classes', []);
        $providers = [];

        if (is_array($configured)) {
            foreach ($configured as $name => $className) {
                $normalizedName = $this->normalizeProviderName((string) $name);
                $normalizedClass = trim((string) $className);

                if ($normalizedName === '' || $normalizedClass === '') {
                    continue;
                }

                if (! class_exists($normalizedClass) || ! is_subclass_of($normalizedClass, AiProvider::class)) {
                    Log::warning('ai.provider.class_invalid', [
                        'provider' => $normalizedName,
                        'class' => $normalizedClass,
                    ]);

                    continue;
                }

                /** @var class-string<AiProvider> $normalizedClass */
                $providers[$normalizedName] = $normalizedClass;
            }
        }

        if ($providers === []) {
            $providers = self::DEFAULT_PROVIDER_CLASSES;
        }

        if (! array_key_exists(self::FALLBACK_PROVIDER, $providers)) {
            $providers[self::FALLBACK_PROVIDER] = self::DEFAULT_PROVIDER_CLASSES[self::FALLBACK_PROVIDER];
        }

        $this->providerClasses = $providers;

        return $this->providerClasses;
    }

    /**
     * @param  class-string<AiProvider>  $providerClass
     */
    private function makeProvider(string $providerClass, string $providerName): AiProvider
    {
        $provider = $this->container->make($providerClass);

        if ($provider instanceof AiProvider) {
            return $provider;
        }

        Log::warning('ai.provider.instance_invalid', [
            'provider' => $providerName,
            'class' => $providerClass,
            'fallback' => self::FALLBACK_PROVIDER,
        ]);

        return $this->container->make(NullAiProvider::class);
    }
}
