<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Model\CsvSeparator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvSeparator::class)]
final class CsvSeparatorTest extends TestCase
{
    public function test_it_defaults_to_a_semicolon_where_numbers_have_a_decimal_comma(): void
    {
        self::assertSame(CsvSeparator::Semicolon, CsvSeparator::defaultFor('PL'));
        self::assertSame(CsvSeparator::Semicolon, CsvSeparator::defaultFor('de'));
        self::assertSame(CsvSeparator::Comma, CsvSeparator::defaultFor('US'));
        self::assertSame(CsvSeparator::Comma, CsvSeparator::defaultFor('GB'));
    }

    public function test_it_pairs_the_field_separator_with_a_decimal_separator(): void
    {
        self::assertSame([';', ','], [CsvSeparator::Semicolon->delimiter(), CsvSeparator::Semicolon->decimalSeparator()]);
        self::assertSame([',', '.'], [CsvSeparator::Comma->delimiter(), CsvSeparator::Comma->decimalSeparator()]);
    }
}
