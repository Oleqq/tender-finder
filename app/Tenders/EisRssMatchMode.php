<?php

namespace App\Tenders;

enum EisRssMatchMode: string
{
    case All = 'all';
    case Any = 'any';
    case Exact = 'exact';
}
