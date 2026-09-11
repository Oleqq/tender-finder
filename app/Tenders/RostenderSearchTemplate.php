<?php

namespace App\Tenders;

final readonly class RostenderSearchTemplate
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
