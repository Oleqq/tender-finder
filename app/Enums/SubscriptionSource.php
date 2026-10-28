<?php

namespace App\Enums;

enum SubscriptionSource: string
{
    case Trial = 'trial';
    case Demo = 'demo';
    case TelegramStars = 'telegram_stars';
    case AdminGrant = 'admin_grant';
}
