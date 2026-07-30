<?php

namespace App\Services;

class CsvExportService
{
    public function safeCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/u', $text) === 1
            ? "'".$text
            : $text;
    }
}
