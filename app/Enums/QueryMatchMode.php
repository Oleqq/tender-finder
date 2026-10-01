<?php

namespace App\Enums;

enum QueryMatchMode: string
{
    case All = 'all';
    case Any = 'any';
    case Exact = 'exact';
    case Phrase = 'phrase';
}
