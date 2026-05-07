<?php

return [
    'default_timeout_seconds' => (int) env('IXC_TIMEOUT_SECONDS', 15),
    'allow_self_signed_default' => (bool) env('IXC_ALLOW_SELF_SIGNED', false),
    'allow_private_hosts' => (bool) env('IXC_ALLOW_PRIVATE_HOSTS', false),
    'debug_log' => (bool) env('IXC_DEBUG_LOG', false),
    'client_alternative_resources' => array_values(array_filter(array_map(
        static fn ($value) => trim((string) $value),
        explode(',', (string) env('IXC_CLIENT_ALTERNATIVE_RESOURCES', 'listar_clientes_por_cpf,listar_clientes_por_telefone,listar_clientes_fibra'))
    ))),
];
