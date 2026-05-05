<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\Contact;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\UploadedFile;

class ContactCsvImportService
{
    private const MAX_ROWS = 10_000;

    /**
     * @return array{imported:int, skipped:int, errors:list<string>}
     */
    public function import(UploadedFile $file, int $companyId): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Não foi possível ler o arquivo.']];
        }

        try {
            return $this->process($handle, $companyId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array{imported:int, skipped:int, errors:list<string>}
     */
    private function process($handle, int $companyId): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $row      = 0;

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Arquivo CSV vazio ou inválido.']];
        }
        $firstLine = ltrim($firstLine, "\xEF\xBB\xBF");

        $delimiter = substr_count($firstLine, ',') >= substr_count($firstLine, ';') ? ',' : ';';

        $header = str_getcsv(trim($firstLine), $delimiter);
        if (empty($header)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Arquivo CSV vazio ou inválido.']];
        }

        $nameCol  = $this->findColumn($header, ['nome', 'name', 'nome completo', 'full name']);
        $phoneCol = $this->findColumn($header, ['telefone', 'phone', 'celular', 'whatsapp', 'fone', 'número']);

        if ($phoneCol === null) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Coluna de telefone não encontrada. Use: telefone, phone, celular, whatsapp.']];
        }

        $existing = Contact::where('company_id', $companyId)
            ->pluck('phone')
            ->flip()
            ->all();

        $batch = [];

        while (($line = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $row++;

            if ($row > self::MAX_ROWS) {
                $errors[] = 'Limite de ' . self::MAX_ROWS . ' linhas atingido. Restante ignorado.';
                break;
            }

            $rawPhone = trim((string) ($line[$phoneCol] ?? ''));
            if ($rawPhone === '') {
                $skipped++;
                continue;
            }

            $phone = PhoneNumberNormalizer::normalizeBrazil($rawPhone);
            if ($phone === '') {
                $errors[] = "Linha {$row}: telefone inválido ({$rawPhone}).";
                $skipped++;
                continue;
            }

            if (isset($existing[$phone])) {
                $skipped++;
                continue;
            }

            $name = $nameCol !== null
                ? mb_substr(trim((string) ($line[$nameCol] ?? '')), 0, 160)
                : '';

            $batch[] = [
                'company_id'  => $companyId,
                'phone'       => $phone,
                'name'        => $name !== '' ? $name : $phone,
                'source'      => 'csv',
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            $existing[$phone] = true;

            if (count($batch) >= 200) {
                Contact::insertOrIgnore($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            Contact::insertOrIgnore($batch);
            $imported += count($batch);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Encontra o índice da coluna pelo nome (case-insensitive, sem acentos).
     *
     * @param  list<string>  $header
     * @param  list<string>  $candidates
     */
    private function findColumn(array $header, array $candidates): ?int
    {
        foreach ($header as $index => $col) {
            $normalized = mb_strtolower(trim((string) $col));
            if (in_array($normalized, $candidates, true)) {
                return $index;
            }
        }

        return null;
    }
}
