<?php

namespace App\Tenders;

use RuntimeException;

final class PlatformCatalogException extends RuntimeException
{
    public function __construct(public readonly string $codeName)
    {
        parent::__construct($codeName);
    }
}
