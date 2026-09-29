<?php

namespace App\Sales\Application;

final class CsvSanitizer
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        if ($text !== '' && in_array(ltrim($text, " \t\n\r\0\x0B\x1C\x1D\x1E\x1F")[0] ?? '', ['=', '+', '-', '@'], true)) {
            return "'".$text;
        }

        return $text;
    }
}
