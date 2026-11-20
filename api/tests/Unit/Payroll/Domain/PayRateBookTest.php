<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Domain;

use App\Payroll\Domain\Model\PayRateBook;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\PayRateMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PayRateBook::class)]
final class PayRateBookTest extends TestCase
{
    public function test_it_takes_the_day_rate_first_then_the_hourly_rate_in_effect(): void
    {
        $anna = Uuid::v7();
        $ben = Uuid::v7();
        $book = PayRateBook::of(
            [
                PayRateMother::hourly($anna, '2026-12-01', 3000),
                PayRateMother::hourly($anna, '2026-06-01', 2800),
                PayRateMother::hourly($ben, '2026-12-15', 3100),
            ],
            [PayRateMother::day($anna, '2026-12-31', 4500), PayRateMother::day($ben, '2026-12-14', 5000)],
        );

        self::assertSame(2800, $book->on($anna, BusinessDate::of(2026, 11, 30))?->getAmount());
        self::assertSame(3000, $book->on($anna, BusinessDate::of(2026, 12, 1))?->getAmount());
        self::assertSame(4500, $book->on($anna, BusinessDate::of(2026, 12, 31))?->getAmount());
        self::assertSame(3000, $book->on($anna, BusinessDate::of(2027, 1, 1))?->getAmount());
        self::assertNull($book->on($anna, BusinessDate::of(2026, 5, 31)));
        self::assertSame(5000, $book->on($ben, BusinessDate::of(2026, 12, 14))?->getAmount(), 'A day rate counts without an hourly rate.');
        self::assertNull($book->on($ben, BusinessDate::of(2026, 12, 13)));
        self::assertNull($book->on(Uuid::v7(), BusinessDate::of(2026, 12, 14)));

        $expected = [$anna->toRfc4122(), $ben->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $book->userIds());
    }

    public function test_it_returns_a_rate_in_another_currency_as_it_is(): void
    {
        $anna = Uuid::v7();

        $rate = PayRateBook::of([PayRateMother::hourly($anna, '2026-01-01', 900, 'EUR')], [])->on($anna, BusinessDate::of(2026, 3, 1));

        self::assertSame('EUR', $rate?->getCurrency());
    }
}
