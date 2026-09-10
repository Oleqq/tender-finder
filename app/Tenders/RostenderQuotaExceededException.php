<?php

namespace App\Tenders;

class RostenderQuotaExceededException extends RostenderApiException
{
    public function __construct()
    {
        parent::__construct('quota_exhausted');
    }
}
