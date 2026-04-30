<?php

declare(strict_types=1);


namespace App\Data;

use Illuminate\Http\JsonResponse;

/**
 * Envelope tipado para o retorno HTTP de Actions.
 * Substitui o padrão array{status: int, body: array<string, mixed>}.
 */
final readonly class ActionResponse
{
    public function __construct(
        public int $status,
        /** @var array<string, mixed> */
        public array $body,
    ) {}

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    public static function ok(array $body = []): self
    {
        return new self(200, $body);
    }

    /** @param array<string, mixed> $body */
    public static function created(array $body = []): self
    {
        return new self(201, $body);
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self(404, ['message' => $message]);
    }

    public static function conflict(string $message): self
    {
        return new self(409, ['message' => $message]);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function unprocessable(string $message, array $errors = []): self
    {
        $body = ['message' => $message];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new self(422, $body);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, ['message' => $message]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function tooManyRequests(string $message, array $extra = []): self
    {
        return new self(429, array_merge(['message' => $message], $extra));
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    public function toResponse(): JsonResponse
    {
        return response()->json($this->body, $this->status);
    }
}
