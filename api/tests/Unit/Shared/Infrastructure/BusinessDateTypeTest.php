<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessDateType::class)]
final class BusinessDateTypeTest extends TestCase
{
    private BusinessDateType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new BusinessDateType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function test_it_declares_a_date_column(): void
    {
        self::assertSame('DATE', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function test_it_stores_a_business_date_as_year_month_day(): void
    {
        self::assertSame('2026-02-03', $this->type->convertToDatabaseValue(BusinessDate::of(2026, 2, 3), $this->platform));
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    public function test_it_refuses_to_store_anything_but_a_business_date(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToDatabaseValue(new \DateTimeImmutable('2026-02-03'), $this->platform);
    }

    public function test_it_reads_a_date_column_into_a_business_date(): void
    {
        $date = $this->type->convertToPHPValue('2026-02-03', $this->platform);

        self::assertInstanceOf(BusinessDate::class, $date);
        self::assertTrue($date->equals(BusinessDate::of(2026, 2, 3)));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function test_it_passes_a_business_date_through_unchanged(): void
    {
        $date = BusinessDate::of(2026, 2, 3);

        self::assertSame($date, $this->type->convertToPHPValue($date, $this->platform));
    }

    public function test_it_refuses_a_value_that_is_not_a_date(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('2026-02-30', $this->platform);
    }

    public function test_it_refuses_a_value_that_is_not_a_string(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue(20260203, $this->platform);
    }
}
