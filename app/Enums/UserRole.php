<?php

namespace App\Enums;

enum UserRole: string
{
    case Subscriber = 'subscriber';
    case SuperAdmin = 'super_admin';
}
