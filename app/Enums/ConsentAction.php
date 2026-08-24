<?php

namespace App\Enums;

enum ConsentAction: string
{
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
