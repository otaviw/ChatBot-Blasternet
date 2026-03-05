<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageMediaStorageService
{
    /**
     * @param  array{provider:string, key:string, url:?string, mime_type:string, size_bytes:?int, width:?int, height:?int}  $result
     */
    public function storeUploadedImage(UploadedFile $file, ?int $companyId = null): array
    {
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new \RuntimeException('Nao foi possivel ler o arquivo enviado.');
        }

        return $this->storeBinaryImage(
            $binary,
            $file->getMimeType() ?: 'application/octet-stream',
            $companyId,
            $file->getClientOriginalExtension() ?: null
        );
    }

    /**
     * @param  array{provider:string, key:string, url:?string, mime_type:string, size_bytes:?int, width:?int, height:?int}  $result
     */
    public function storeBinaryImage(
        string $binary,
        ?string $mimeType = null,
        ?int $companyId = null,
        ?string $extensionHint = null
    ): array {
        $disk = (string) config('whatsapp.media_disk', 'public');
        $mime = $this->normalizeMimeType($mimeType);
        $extension = $this->resolveExtension($mime, $extensionHint);
        $directory = $this->buildDirectory($companyId);
        $fileName = Str::uuid()->toString().'.'.$extension;
        $key = "{$directory}/{$fileName}";

        Storage::disk($disk)->put($key, $binary, ['visibility' => 'public']);

        $dimensions = @getimagesizefromstring($binary) ?: [null, null];
        $width = is_int($dimensions[0] ?? null) ? $dimensions[0] : null;
        $height = is_int($dimensions[1] ?? null) ? $dimensions[1] : null;

        return [
            'provider' => $disk,
            'key' => $key,
            'url' => $this->resolveUrl($disk, $key),
            'mime_type' => $mime,
            'size_bytes' => strlen($binary),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function buildDirectory(?int $companyId): string
    {
        $prefix = (string) config('whatsapp.media_prefix', 'whatsapp/messages');
        if (! $companyId) {
            return $prefix.'/shared';
        }

        return $prefix.'/company_'.$companyId;
    }

    private function resolveUrl(string $disk, string $key): ?string
    {
        try {
            return Storage::disk($disk)->url($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeMimeType(?string $mimeType): string
    {
        $value = trim((string) $mimeType);
        if ($value === '') {
            return 'application/octet-stream';
        }

        return $value;
    }

    private function resolveExtension(string $mimeType, ?string $extensionHint): string
    {
        $hint = strtolower(trim((string) $extensionHint));
        if ($hint !== '') {
            return $hint;
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
