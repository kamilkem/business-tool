<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Http\Shared\Csv;

use App\Organization\Domain\Model\CsvSeparator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ui\Http\Shared\Csv\Csv;

#[CoversClass(Csv::class)]
final class CsvTest extends TestCase
{
    public function test_it_writes_utf8_with_a_byte_order_mark_crlf_and_the_separator(): void
    {
        $rows = [['Name', 'Total'], ['Zażółć', '12,50']];

        self::assertSame("\u{FEFF}Name;Total\r\nZażółć;12,50\r\n", Csv::write($rows, CsvSeparator::Semicolon));
        self::assertSame("\u{FEFF}Name,Total\r\nZażółć,\"12,50\"\r\n", Csv::write($rows, CsvSeparator::Comma));
    }

    public function test_it_quotes_a_field_with_the_separator_a_quote_or_a_line_break(): void
    {
        $csv = Csv::write([['a;b', 'say "hi"', "two\nlines", 'a,b']], CsvSeparator::Semicolon);

        self::assertSame("\u{FEFF}\"a;b\";\"say \"\"hi\"\"\";\"two\nlines\";a,b\r\n", $csv);
    }

    public function test_it_keeps_a_spreadsheet_from_running_a_text_cell_as_a_formula_but_leaves_negative_numbers(): void
    {
        $csv = Csv::write([['=SUM(A1)', '+48 600', '@me', '-x', '-12,50', '-0:30:00', '-1.5']], CsvSeparator::Comma);

        self::assertSame("\u{FEFF}'=SUM(A1),'+48 600,'@me,'-x,\"-12,50\",-0:30:00,-1.5\r\n", $csv);
    }
}
