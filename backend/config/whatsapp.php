<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook (Meta for Developers)
    |--------------------------------------------------------------------------
    | Verificação: o Meta envia GET com hub.mode, hub.verify_token, hub.challenge.
    | Defina o mesmo valor em .env e no painel do Meta.
    */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'seu_token_secreto_aqui'),

    /*
    |--------------------------------------------------------------------------
    | Credenciais da API (preencher quando tiver o app no Meta)
    |--------------------------------------------------------------------------
    */
    'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v21.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'), // ID do número no Meta
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),        // Token permanente ou temporário
    'media_disk' => env('WHATSAPP_MEDIA_DISK', 'public'),
    'media_prefix' => env('WHATSAPP_MEDIA_PREFIX', 'whatsapp/messages'),
    'media_max_size_kb' => (int) env('WHATSAPP_MEDIA_MAX_SIZE_KB', 5120),

];
