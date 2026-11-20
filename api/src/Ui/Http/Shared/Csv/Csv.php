<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Csv;

use App\Organization\Domain\Model\CsvSeparator;

/**
 * Writes a CSV file the way a spreadsheet opens it as columns (RFC 4180): UTF-8 with a byte order
 * mark (so Excel reads accented letters), CRLF line ends, the organization's field separator, and a
 * field quoted when it holds the separator, a quote or a line break. A text cell that a spreadsheet
 * would run as a formula (=, +, -, @ first) gets a leading apostrophe; numbers like "-12,50" are left
 * alone.
 */
final class Csv
{
    public const string MEDIA_TYPE = 'text/csv; charset=UTF-8';

    private const string BOM = "\u{FEFF}";

    /** @param list<list<string>> $rows the first one the header */
    public static function write(array $rows, CsvSeparator $separator): string
    {
        $delimiter = $separator->delimiter();
        $lines = array_map(
            static fn (array $row): string => implode($delimiter, array_map(static fn (string $cell): string => self::cell($cell, $delimiter), $row)),
            $rows,
        );

        return self::BOM.implode("\r\n", $lines)."\r\n";
    }

    private static function cell(string $value, string $delimiter): string
    {
        if (1 === preg_match('/^[=+\-@\t\r]/', $value) && 1 !== preg_match('/^-\d[\d.,:]*$/D', $value)) {
            $value = "'".$value;
        }
        if (str_contains($value, $delimiter) || false !== strpbrk($value, "\"\r\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
