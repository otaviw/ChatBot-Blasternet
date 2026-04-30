<?php

declare(strict_types=1);


namespace App\Support;

class ProductFunnels
{
    public const CADASTRO = 'cadastro';
    public const LOGIN = 'login';
    public const CHATBOT = 'uso_chatbot';
    public const TRANSFERENCIA = 'transferencia';
    public const FEATURE_PRINCIPAL = 'conversao_feature_principal';

    /**
     * @return array<string, array<int, string>>
     */
    public static function steps(): array
    {
        return [
            self::CADASTRO => ['company_created', 'user_created'],
            self::LOGIN => ['attempt', 'success'],
            self::CHATBOT => ['inbound_received', 'auto_reply_sent'],
            self::TRANSFERENCIA => ['requested', 'completed'],
            self::FEATURE_PRINCIPAL => ['manual_or_template_sent', 'conversation_closed'],
        ];
    }
}
