<?php

return [
    'default_timeout_seconds' => (int) env('IXC_TIMEOUT_SECONDS', 15),
    'allow_self_signed_default' => (bool) env('IXC_ALLOW_SELF_SIGNED', false),
    'allow_private_hosts' => (bool) env('IXC_ALLOW_PRIVATE_HOSTS', false),
];
