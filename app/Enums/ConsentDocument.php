<?php

namespace App\Enums;

enum ConsentDocument: string
{
    case Offer = 'offer';
    case Privacy = 'privacy';
    case Terms = 'terms';
    case Consent = 'consent';
}
