<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Model\KsefInvoiceExport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KsefInvoiceExport::class)]
final class KsefInvoiceExportTest extends TestCase
{
    public function test_it_keeps_its_range_to_the_whole_second_in_utc(): void
    {
        $export = new KsefInvoiceExport('ref', 'key', new \DateTimeImmutable('2026-11-01 10:00:00.987 +01:00'), new \DateTimeImmutable('2026-11-02 00:00:00.5 UTC'), new \DateTimeImmutable('2026-11-02 00:00:01.2 UTC'));

        self::assertSame('2026-11-01T09:00:00.000000+00:00', $export->from->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-11-02T00:00:00.000000+00:00', $export->to->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_it_covers_the_cursors_in_its_range(): void
    {
        $export = new KsefInvoiceExport('ref', 'key', new \DateTimeImmutable('2026-11-01 00:00 UTC'), new \DateTimeImmutable('2026-11-02 00:00 UTC'), new \DateTimeImmutable('2026-11-02 00:00 UTC'));

        self::assertTrue($export->covers(new \DateTimeImmutable('2026-11-01 00:00 UTC')));
        self::assertTrue($export->covers(new \DateTimeImmutable('2026-11-01 13:00 UTC')));
        self::assertFalse($export->covers(new \DateTimeImmutable('2026-10-31 23:59 UTC')));
        self::assertFalse($export->covers(new \DateTimeImmutable('2026-11-02 00:00 UTC')));
    }

    public function test_it_refuses_an_empty_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KsefInvoiceExport('ref', 'key', new \DateTimeImmutable('2026-11-01 00:00:00.2 UTC'), new \DateTimeImmutable('2026-11-01 00:00:00.9 UTC'), new \DateTimeImmutable('2026-11-02 00:00 UTC'));
    }
}
