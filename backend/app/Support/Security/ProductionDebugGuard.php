<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Exceptions\ConfigurationException;

class ProductionDebugGuard
{
    public static function assertSafe(string $environment, bool $debug): void
    {
        if (mb_strtolower($environment) === 'production' && $debug) {
            throw new ConfigurationException(
                'Configuração insegura detectada: APP_DEBUG=true em APP_ENV=production. Defina APP_DEBUG=false.'
            );
        }
    }
}
