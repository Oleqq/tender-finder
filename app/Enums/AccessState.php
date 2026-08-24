<?php

namespace App\Enums;

enum AccessState: string
{
    case Preview = 'preview';
    case Trialing = 'trialing';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
