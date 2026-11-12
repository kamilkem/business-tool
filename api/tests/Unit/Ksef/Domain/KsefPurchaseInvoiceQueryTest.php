<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use PHPUnit\Framework\TestCase;

final class KsefPurchaseInvoiceQueryTest extends TestCase
{
    public function test_it_keeps_the_range_in_utc(): void
    {
        $query = new KsefPurchaseInvoiceQuery(
            new \DateTimeImmutable('2026-11-01 00:00', new \DateTimeZone('Europe/Warsaw')),
            new \DateTimeImmutable('2026-11-12 12:00', new \DateTimeZone('Europe/Warsaw')),
        );

        self::assertSame('2026-10-31T23:00:00+00:00', $query->from->format(\DATE_ATOM));
        self::assertSame('2026-11-12T11:00:00+00:00', $query->to->format(\DATE_ATOM));
        self::assertSame(0, $query->pageOffset);
    }

    public function test_it_accepts_a_range_of_exactly_100_days(): void
    {
        $query = new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-08-04 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00 UTC'));

        self::assertSame('2026-11-12', $query->to->format('Y-m-d'));
    }

    public function test_it_rejects_a_range_longer_than_100_days(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-08-04 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00:01 UTC'));
    }

    public function test_it_rejects_an_empty_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-11-12 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00 UTC'));
    }

    public function test_it_rejects_a_negative_page_offset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-11-01 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00 UTC'), -1);
    }

    public function test_it_moves_to_the_next_page_of_the_same_range(): void
    {
        $query = new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-11-01 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00 UTC'));

        $next = $query->nextPage()->nextPage();

        self::assertSame(2, $next->pageOffset);
        self::assertEquals($query->from, $next->from);
        self::assertEquals($query->to, $next->to);
    }
}
