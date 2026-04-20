<?php

namespace App\Support\Enums;

enum BotFlow: string
{
    case MAIN               = 'main';
    case SUPPORT            = 'support';
    case APPOINTMENTS       = 'appointments';
    case CANCEL_APPOINTMENT = 'cancel_appointment';
}
