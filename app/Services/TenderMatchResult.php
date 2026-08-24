<?php

namespace App\Services;

final readonly class TenderMatchResult
{
    /** @param array<string, mixed> $reasons */
    public function __construct(public bool $matches, public array $reasons) {}
}
