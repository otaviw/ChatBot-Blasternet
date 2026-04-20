<?php

namespace App\Exceptions;

use RuntimeException;

class AppointmentBusinessException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly ?array $details = null
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }
}

