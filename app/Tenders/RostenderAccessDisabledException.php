<?php

namespace App\Tenders;

class RostenderAccessDisabledException extends RostenderApiException
{
    public function __construct()
    {
        parent::__construct('source_disabled');
    }
}
