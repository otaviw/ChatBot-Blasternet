<?php

declare(strict_types=1);


namespace App\Services\Ai\Providers;

interface AiStreamingProvider
{
    /**
     * Stream the AI reply chunk by chunk, calling $onChunk for each text delta.
     * Returns the same shape as AiProvider::reply() after streaming completes.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @param  callable(string):void  $onChunk
     * @return array{
     *     ok:bool,
     *     text:?string,
     *     error:mixed,
     *     meta:array<string, mixed>|null
     * }
     */
    public function streamReply(array $messages, array $options, callable $onChunk): array;
}
