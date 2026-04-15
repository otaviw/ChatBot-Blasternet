<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MessageMediaStorageService
{
    /**
     * @param  array{provider:string, key:string, url:?string, mime_type:string, size_bytes:?int, width:?int, height:?int}  $result
     */
    /**
     * @return array{provider:string, key:string, url:?string, mime_type:string, size_bytes:int}
     */
    public function storeSupportTicketImage(UploadedFile $file): array
    {
        $disk = (string) config('whatsapp.support_attachments_disk', 'local');
        $mime = $this->normalizeMimeType($file->getMimeType());
        $extension = $this->resolveExtension($mime, $file->getClientOriginalExtension());
        $directory = trim((string) config('whatsapp.support_attachments_prefix', 'support/attachments'), '/');
        $fileName = Str::uuid()->toString().'.'.$extension;
        $key = "{$directory}/{$fileName}";

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new RuntimeException('Não foi possível ler o arquivo de anexo.');
        }

        Storage::disk($disk)->put($key, $binary, ['visibility' => 'private']);

        return [
            'provider' => $disk,
            'key' => $key,
            'url' => null,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * @param  array{provider:string, key:string, url:?string, mime_type:string, size_bytes:?int, width:?int, height:?int}  $result
     */
    public function storeUploadedImage(UploadedFile $file, ?int $companyId = null): array
    {
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo enviado.');
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
            'provider'   => $disk,
            'key'        => $key,
            'url'        => $this->resolveUrl($disk, $key),
            'mime_type'  => $this->normalizeMimeType($mimeType, preserveCodec: true),
            'size_bytes' => strlen($binary),
            'width'      => $width,
            'height'     => $height,
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

    private function normalizeMimeType(?string $mimeType, bool $preserveCodec = false): string
    {
        $value = trim((string) $mimeType);
        if ($value === '') {
            return 'application/octet-stream';
        }

        if ($preserveCodec) {
            return $value;
        }

        // Strip codec/parameter suffix apenas para resolução de extensão.
        // Ex: "audio/ogg; codecs=opus" → "audio/ogg"
        if (str_contains($value, ';')) {
            $value = trim(explode(';', $value)[0]);
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
            // Imagens
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/gif'     => 'gif',
            // Áudio
            'audio/ogg'     => 'ogg',
            'audio/mpeg'    => 'mp3',
            'audio/mp4'     => 'm4a',
            'audio/aac'     => 'aac',
            'audio/wav'     => 'wav',
            'audio/webm'    => 'weba',
            // Vídeo
            'video/mp4'     => 'mp4',
            'video/mpeg'    => 'mpeg',
            'video/webm'    => 'webm',
            'video/3gpp'    => '3gp',
            'video/quicktime' => 'mov',
            // Documentos
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain'    => 'txt',
            'application/zip' => 'zip',
            default         => 'bin',
        };
    }
}
