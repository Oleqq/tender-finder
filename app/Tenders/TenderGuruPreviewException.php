<?php

namespace App\Tenders;

use RuntimeException;

class TenderGuruPreviewException extends RuntimeException
{
    public function __construct(public readonly string $codeName)
    {
        parent::__construct($codeName);
    }
}
