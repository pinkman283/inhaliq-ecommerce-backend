<?php

namespace App\Services;

class CsvSanitizer
{
    /**
     * Sanitize a single CSV cell value against spreadsheet formula injection (CSV / DDE Injection).
     * Non-numeric strings beginning with =, +, -, @, \t, or \r are prefixed with a single quote (').
     */
    public static function sanitize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        // If string begins with formula trigger character (=, +, -, @, tab, CR), prefix with single quote
        if (preg_match('/^[=\+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Sanitize an entire CSV row array.
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map([self::class, 'sanitize'], $row);
    }
}
