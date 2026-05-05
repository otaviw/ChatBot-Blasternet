<?php

declare(strict_types=1);


namespace App\Data;

/**
 * Resultado tipado de verificação de limite de uso.
 * Substitui os arrays de múltiplos formatos retornados por CompanyUsageLimitsService.
 */
final readonly class UsageLimitResult
{
    public function __construct(
        public bool $allowed,
        public bool $warning,
        /** Contagem atual (mensagens enviadas ou usuários ativos). */
        public int $count,
        public ?int $limit,
        public ?string $warningMessage,
        public ?string $errorMessage,
    ) {}


    public static function allowed(int $count, ?int $limit, bool $warning, ?string $warningMessage): self
    {
        return new self(
            allowed: true,
            warning: $warning,
            count: $count,
            limit: $limit,
            warningMessage: $warningMessage,
            errorMessage: null,
        );
    }

    public static function blocked(int $count, int $limit, string $errorMessage): self
    {
        return new self(
            allowed: false,
            warning: false,
            count: $count,
            limit: $limit,
            warningMessage: null,
            errorMessage: $errorMessage,
        );
    }

    public static function unlimited(): self
    {
        return new self(
            allowed: true,
            warning: false,
            count: 0,
            limit: null,
            warningMessage: null,
            errorMessage: null,
        );
    }


    /**
     * Gera um ActionResponse 429 padronizado para limite atingido.
     *
     * @param  array<string, mixed>  $extra  Campos adicionais no body (ex: 'limit_blocked').
     */
    public function toBlockedResponse(array $extra = []): ActionResponse
    {
        return ActionResponse::tooManyRequests(
            $this->errorMessage ?? 'Limite atingido.',
            array_merge(['limit_blocked' => true, 'used' => $this->count, 'limit' => $this->limit], $extra)
        );
    }

    /**
     * Retorna os campos de aviso de uso para incluir no body de respostas bem-sucedidas.
     *
     * @return array<string, mixed>
     */
    public function warningPayload(): array
    {
        return [
            'usage_warning' => $this->warning,
            'usage_message' => $this->warningMessage,
            'usage_used'    => $this->count,
            'usage_limit'   => $this->limit,
        ];
    }
}
