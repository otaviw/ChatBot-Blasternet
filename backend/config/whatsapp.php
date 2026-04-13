<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook (Meta for Developers)
    |--------------------------------------------------------------------------
    | Verificacao: o Meta envia GET com hub.mode, hub.verify_token, hub.challenge.
    | Defina o mesmo valor em .env e no painel do Meta.
    */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'blasternet_verify_token'),

    /*
    |--------------------------------------------------------------------------
    | App Secret (obrigatório em produção)
    |--------------------------------------------------------------------------
    | Chave secreta do App no painel Meta for Developers (Configurações do app
    | → Básico → Chave secreta do app). Usada para validar a assinatura
    | X-Hub-Signature-256 de cada webhook recebido.
    |
    | OBRIGATÓRIO: sem este valor o servidor rejeita todos os webhooks e
    | lança uma ConfigurationException no boot da aplicação HTTP.
    */
    'app_secret' => env('WHATSAPP_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Credenciais da API
    |--------------------------------------------------------------------------
    */
    'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v22.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'), // Fallback single-tenant
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),       // Global ou por company (DB)
    'media_disk' => env('WHATSAPP_MEDIA_DISK', 'public'),
    'media_prefix' => env('WHATSAPP_MEDIA_PREFIX', 'whatsapp/messages'),
    'media_max_size_kb' => (int) env('WHATSAPP_MEDIA_MAX_SIZE_KB', 5120),

];
