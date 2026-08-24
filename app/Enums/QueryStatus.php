<?php

namespace App\Enums;

enum QueryStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Frozen = 'frozen';
    case Deleted = 'deleted';
}
