<?php

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

        // Detecta e pula BOM UTF-8
        $first = fgets($handle);
        if ($first !== false) {
            $first = ltrim($first, "\xEF\xBB\xBF");
            rewind($handle);
            fwrite($handle, $first);
            rewind($handle);
        }

        $header = fgetcsv($handle, 1000, ',') ?: fgetcsv($handle, 1000, ';');
        if ($header === false || $header === null) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Arquivo CSV vazio ou inválido.']];
        }

        // Detecta delimitador pela contagem de colunas no header
        $delimiter = count(str_getcsv(implode(',', $header), ',')) >= 2 ? ',' : ';';

        // Reinicia e relê com o delimitador correto
        rewind($handle);
        fgetcsv($handle, 1000, $delimiter); // pula header

        $nameCol  = $this->findColumn($header, ['nome', 'name', 'nome completo', 'full name']);
        $phoneCol = $this->findColumn($header, ['telefone', 'phone', 'celular', 'whatsapp', 'fone', 'número']);

        if ($phoneCol === null) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Coluna de telefone não encontrada. Use: telefone, phone, celular, whatsapp.']];
        }

        // Pré-carrega phones existentes da empresa para checagem rápida em memória
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
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            // Marca como visto para evitar duplicatas dentro do próprio CSV
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
