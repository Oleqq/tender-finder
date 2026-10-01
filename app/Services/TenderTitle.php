<?php

namespace App\Services;

final class TenderTitle
{
    public static function display(string $title): string
    {
        $title = trim($title);

        return preg_match('/^(.{20,}?)\s+\1$/us', $title, $repeated) === 1
            ? $repeated[1]
            : $title;
    }
}
