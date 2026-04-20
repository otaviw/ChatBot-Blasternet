<?php

namespace App\Support\Enums;

enum MessageType: string
{
    case TEXT        = 'text';
    case IMAGE       = 'image';
    case VIDEO       = 'video';
    case AUDIO       = 'audio';
    case DOCUMENT    = 'document';
    case LOCATION    = 'location';
    case TEMPLATE    = 'template';
    case INTERACTIVE = 'interactive';
}
