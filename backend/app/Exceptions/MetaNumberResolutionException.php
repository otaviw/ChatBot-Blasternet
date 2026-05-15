<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MetaNumberResolutionException extends RuntimeException
{
    public function __construct(
        string $message = 'NO_ACTIVE_META_NUMBER_FOR_COMPANY',
        private readonly string $errorCode = 'NO_ACTIVE_META_NUMBER_FOR_COMPANY'
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

