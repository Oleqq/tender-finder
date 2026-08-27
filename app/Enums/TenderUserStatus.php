<?php

namespace App\Enums;

enum TenderUserStatus: string
{
    case New = 'new';
    case Favorite = 'favorite';
    case Potential = 'potential';
    case Dismissed = 'dismissed';
    case Archived = 'archived';
}
