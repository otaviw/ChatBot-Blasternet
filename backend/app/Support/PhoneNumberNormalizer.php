<?php

declare(strict_types=1);


namespace App\Support;

final class PhoneNumberNormalizer
{
    /**
     * Normaliza telefone BR para formato canônico em dígitos.
     * Regra principal: usa E.164 simplificado "55 + DDD + numero".
     */
    public static function normalizeBrazil(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if ($digits === '') {
            return '';
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '55')) {
            $national = substr($digits, 2);
            if ($national === false || $national === '') {
                return '';
            }

            return '55' . self::normalizeNationalNumber($national);
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . self::normalizeNationalNumber($digits);
        }

        return $digits;
    }

    /**
     * Gera variações úteis para localizar registros legados.
     *
     * @return array<int, string>
     */
    public static function variantsForLookup(string $input): array
    {
        $canonical = self::normalizeBrazil($input);
        if ($canonical === '') {
            return [];
        }

        $variants = [$canonical];

        if (str_starts_with($canonical, '55')) {
            $national = substr($canonical, 2);
            if ($national !== false && $national !== '') {
                $variants[] = $national;

                if (strlen($national) === 11 && $national[2] === '9') {
                    $subscriberWithoutNine = substr($national, 3);
                    if ($subscriberWithoutNine !== false && $subscriberWithoutNine !== '') {
                        $withoutNineNational = substr($national, 0, 2) . $subscriberWithoutNine;
                        $variants[] = '55' . $withoutNineNational;
                        $variants[] = $withoutNineNational;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private static function normalizeNationalNumber(string $national): string
    {
        if (strlen($national) === 10) {
            $ddd = substr($national, 0, 2);
            $subscriber = substr($national, 2);
            if ($subscriber !== false && $subscriber !== '' && self::looksLikeMobile8Digits($subscriber)) {
                return $ddd . '9' . $subscriber;
            }

            return $national;
        }

        return $national;
    }

    private static function looksLikeMobile8Digits(string $subscriber): bool
    {
        if (strlen($subscriber) !== 8) {
            return false;
        }

        $first = $subscriber[0] ?? '';

        return in_array($first, ['6', '7', '8', '9'], true);
    }
}

