<?php

declare(strict_types=1);


namespace App\Services\Ai\Tools;

interface AiToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array;
}

