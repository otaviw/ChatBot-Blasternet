<?php

declare(strict_types=1);


namespace App\Services\Ai\Providers;

interface AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array{
     *     ok:bool,
     *     text:?string,
     *     error:mixed,
     *     meta:array{
     *         provider?:string,
     *         model?:string,
     *         message?:string,
     *         usage?:array<string, mixed>
     *     }|array<string, mixed>|null
     * }
     */
    public function reply(array $messages, array $options = []): array;
}
