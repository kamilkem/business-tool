<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Tests\Fixtures\Ksef\InMemoryKsefClient;
use PHPUnit\Framework\TestCase;

/** The fake that handler and API tests of the sync (#52) rely on behaves like the real port. */
final class InMemoryKsefClientTest extends TestCase
{
    public function test_it_rejects_a_token_it_does_not_know(): void
    {
        $ksef = new InMemoryKsefClient();
        $ksef->acceptToken('7251964308', 'fixture-ksef-token');

        $this->expectException(KsefCredentialsRejectedException::class);

        $ksef->authenticateWithKsefToken('7251964308', 'another-token');
    }

    public function test_it_lists_the_buyers_invoices_by_permanent_storage_date_up_to_the_high_water_mark(): void
    {
        $ksef = new InMemoryKsefClient();
        $ksef->acceptToken('7251964308', 'fixture-ksef-token');
        $ksef->addInvoice('7251964308', 'B', new \DateTimeImmutable('2026-11-04 10:00 UTC'), '<b/>');
        $ksef->addInvoice('7251964308', 'A', new \DateTimeImmutable('2026-11-03 10:00 UTC'), '<a/>');
        $ksef->addInvoice('7251964308', 'C', new \DateTimeImmutable('2026-11-10 10:00 UTC'), '<c/>');
        $ksef->addInvoice('9999999999', 'OTHER', new \DateTimeImmutable('2026-11-03 10:00 UTC'), '<x/>');
        $ksef->permanentStorageHwmDate = new \DateTimeImmutable('2026-11-08 00:00 UTC');
        $session = $ksef->authenticateWithKsefToken('7251964308', 'fixture-ksef-token');

        $page = $ksef->queryPurchaseInvoiceMetadata($session, new KsefPurchaseInvoiceQuery(
            new \DateTimeImmutable('2026-11-01 00:00 UTC'),
            new \DateTimeImmutable('2026-11-12 00:00 UTC'),
        ));

        self::assertSame(['A', 'B'], array_map(static fn ($metadata): string => $metadata->ksefNumber, $page->invoices));
        self::assertFalse($page->hasMore);
        self::assertEquals(new \DateTimeImmutable('2026-11-08 00:00 UTC'), $page->permanentStorageHwmDate);
        self::assertSame(base64_encode(hash('sha256', '<a/>', true)), $page->invoices[0]->invoiceHash);
        self::assertSame('<b/>', $ksef->downloadInvoice($session, 'B'));
        self::assertSame(['B'], $ksef->downloaded);
    }

    public function test_it_throws_an_injected_failure_once(): void
    {
        $ksef = new InMemoryKsefClient();
        $ksef->acceptToken('7251964308', 'fixture-ksef-token');
        $ksef->failNextCallWith(new KsefRateLimitedException(30, 'limit'));

        try {
            $ksef->authenticateWithKsefToken('7251964308', 'fixture-ksef-token');
            self::fail('Expected the injected failure.');
        } catch (KsefRateLimitedException) {
        }
        $session = $ksef->authenticateWithKsefToken('7251964308', 'fixture-ksef-token');
        $ksef->endSession($session);

        self::assertSame([$session->referenceNumber], $ksef->endedSessions);
        $this->expectException(KsefRequestFailedException::class);
        $ksef->downloadInvoice($session, 'A');
    }
}
