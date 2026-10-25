<?php

namespace Tests\Unit;

use App\Services\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    public function test_sanitizes_dangerous_formula_characters(): void
    {
        // Leading =, +, -, @, \t, \r must be prefixed with single quote (')
        $this->assertEquals("'=SUM(1+1)", CsvSanitizer::sanitize('=SUM(1+1)'));
        $this->assertEquals("'+1234567890", CsvSanitizer::sanitize('+1234567890'));
        $this->assertEquals("'-calc.exe", CsvSanitizer::sanitize('-calc.exe'));
        $this->assertEquals("'@SUM", CsvSanitizer::sanitize('@SUM'));
        $this->assertEquals("'\ttab", CsvSanitizer::sanitize("\ttab"));
        $this->assertEquals("'\rreturn", CsvSanitizer::sanitize("\rreturn"));
    }

    public function test_preserves_safe_values_and_numeric_values(): void
    {
        // Safe strings
        $this->assertEquals("John Doe", CsvSanitizer::sanitize("John Doe"));
        $this->assertEquals("user@example.com", CsvSanitizer::sanitize("user@example.com"));

        // Pure numbers (should not be prefixed with quote)
        $this->assertEquals(100, CsvSanitizer::sanitize(100));
        $this->assertEquals("100.50", CsvSanitizer::sanitize("100.50"));
        $this->assertEquals(0, CsvSanitizer::sanitize(0));
        $this->assertEquals("", CsvSanitizer::sanitize(""));
        $this->assertNull(CsvSanitizer::sanitize(null));
    }

    public function test_sanitizes_entire_csv_row(): void
    {
        $rawRow = [
            '1001',
            '=cmd|\'/C calc\'!A0',
            '+01700000000',
            'Valid Name',
            250.75,
        ];

        $sanitized = CsvSanitizer::sanitizeRow($rawRow);

        $this->assertEquals([
            '1001',
            "'=cmd|'/C calc'!A0",
            "'+01700000000",
            'Valid Name',
            250.75,
        ], $sanitized);
    }
}
