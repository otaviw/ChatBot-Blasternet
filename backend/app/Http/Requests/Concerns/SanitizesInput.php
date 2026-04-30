<?php

declare(strict_types=1);


namespace App\Http\Requests\Concerns;

/**
 * Trait para sanitização de inputs antes da validação.
 *
 * Uso: chame os métodos dentro de prepareForValidation() no FormRequest.
 * Nunca sanitiza senhas, tokens ou payloads binários/assinados.
 */
trait SanitizesInput
{
    /**
     * Remove tags HTML perigosas e entidades codificadas que poderiam
     * ser usadas para XSS. Preserva quebras de linha para campos de texto.
     */
    protected function stripTags(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Remove tags com conteúdo: script, style, iframe, object, embed, form
        $value = (string) preg_replace(
            '/<(script|style|iframe|object|embed|form|svg)[^>]*>.*?<\/\1>/is',
            '',
            $value
        );

        // Remove atributos de evento inline: onclick=, onerror=, onload= etc.
        $value = (string) preg_replace('/\s+on\w+\s*=\s*(["\'])[^"\']*\1/i', '', $value);

        // Remove javascript: e vbscript: em qualquer atributo
        $value = (string) preg_replace('/\b(javascript|vbscript)\s*:/i', '', $value);

        // Strip todas as tags HTML restantes
        $value = strip_tags($value);

        // Decodifica entidades HTML e strip de novo para pegar &lt;script&gt; etc.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);

        return trim($value);
    }

    /**
     * Sanitiza texto de mensagem — preserva quebras de linha mas remove HTML.
     */
    protected function cleanText(?string $value): string
    {
        return $this->stripTags($value);
    }

    /**
     * Sanitiza campos de nome — colapsa whitespace e remove HTML.
     */
    protected function cleanName(?string $value): string
    {
        $clean = $this->stripTags($value);

        // Colapsa múltiplos espaços/tabs/newlines em espaço único
        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /**
     * Normaliza e-mail: lowercase + trim (sem strip_tags; e-mails não têm HTML).
     */
    protected function cleanEmail(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
