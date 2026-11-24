<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Ksef;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\KsefClientInterface;
use App\Ksef\Domain\Model\KsefFormCode;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Shared\Domain\Time\BusinessDate;

/**
 * KSeF for handler and API tests (adr/0023 §8): accepted tokens per NIP, purchase invoices with their
 * XML, and a queue of failures to inject. It keeps what the code under test did, for assertions.
 */
final class InMemoryKsefClient implements KsefClientInterface
{
    /** @var array<string, string> NIP => accepted KSeF token */
    private array $tokens = [];

    /** @var array<string, list<array{metadata: KsefInvoiceMetadata, xml: string}>> NIP => invoices */
    private array $invoices = [];

    /** @var list<KsefException> */
    private array $failures = [];

    private int $sessionCount = 0;

    /** Below this instant results no longer change; null means the query's `to`. */
    public ?\DateTimeImmutable $permanentStorageHwmDate = null;

    /** @var list<string> */
    public array $downloaded = [];

    /** When set, a query returns at most this many records and says the result is truncated. */
    public ?int $truncateAfter = null;

    /** @var array<string, true> KSeF numbers whose download does not match their hash */
    private array $corrupted = [];

    /** @var array<string, KsefException> KSeF number => failure of its download */
    private array $downloadFailures = [];

    /** @var list<string> reference numbers */
    public array $endedSessions = [];

    public function acceptToken(string $nip, string $ksefToken): void
    {
        $this->tokens[$nip] = $ksefToken;
    }

    public function addInvoice(
        string $buyerNip,
        string $ksefNumber,
        \DateTimeImmutable $permanentStorageDate,
        string $xml,
        KsefFormCode $formCode = new KsefFormCode(KsefFormCode::FA3_SYSTEM_CODE, '1-0E', 'FA'),
        /** Default: the day it was stored, in Warsaw. */
        ?BusinessDate $issueDate = null,
    ): KsefInvoiceMetadata {
        $metadata = new KsefInvoiceMetadata(
            $ksefNumber,
            $formCode,
            base64_encode(hash('sha256', $xml, true)),
            $permanentStorageDate->setTimezone(new \DateTimeZone('UTC')),
            $issueDate ?? BusinessDate::containing($permanentStorageDate, new \DateTimeZone('Europe/Warsaw')),
        );
        $this->invoices[$buyerNip][] = ['metadata' => $metadata, 'xml' => $xml];

        return $metadata;
    }

    /** The download of this invoice returns other content than the metadata's hash says. */
    public function corruptDownloadOf(string $ksefNumber): void
    {
        $this->corrupted[$ksefNumber] = true;
    }

    /** The next download of this invoice throws this. */
    public function failDownloadOf(string $ksefNumber, KsefException $failure): void
    {
        $this->downloadFailures[$ksefNumber] = $failure;
    }

    /** The next call, whichever it is, throws this. */
    public function failNextCallWith(KsefException $failure): void
    {
        $this->failures[] = $failure;
    }

    public function authenticateWithKsefToken(string $nip, #[\SensitiveParameter] string $ksefToken): KsefSession
    {
        $this->throwQueuedFailure();
        if (($this->tokens[$nip] ?? null) !== $ksefToken) {
            throw KsefCredentialsRejectedException::authenticationFailed(450, 'Uwierzytelnianie zakończone niepowodzeniem z powodu błędnego tokenu', ['Nieprawidłowy token']);
        }

        $reference = \sprintf('in-memory-%d', ++$this->sessionCount);

        return new KsefSession(
            $nip,
            $reference,
            $reference.'-access',
            new \DateTimeImmutable('+15 minutes'),
            $reference.'-refresh',
            new \DateTimeImmutable('+7 days'),
        );
    }

    public function queryPurchaseInvoiceMetadata(KsefSession $session, KsefPurchaseInvoiceQuery $query): KsefInvoiceMetadataPage
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);

        $to = null === $this->permanentStorageHwmDate ? $query->to : min($query->to, $this->permanentStorageHwmDate);
        $matching = array_values(array_filter(
            array_map(static fn (array $invoice): KsefInvoiceMetadata => $invoice['metadata'], $this->invoices[$session->contextNip] ?? []),
            static fn (KsefInvoiceMetadata $metadata): bool => $metadata->permanentStorageDate >= $query->from && $metadata->permanentStorageDate < $to,
        ));
        usort($matching, static fn (KsefInvoiceMetadata $a, KsefInvoiceMetadata $b): int => $a->permanentStorageDate <=> $b->permanentStorageDate);

        if (null !== $this->truncateAfter && \count($matching) > $this->truncateAfter) {
            return new KsefInvoiceMetadataPage(\array_slice($matching, 0, $this->truncateAfter), false, true, $to);
        }

        $offset = $query->pageOffset * KsefPurchaseInvoiceQuery::PAGE_SIZE;

        return new KsefInvoiceMetadataPage(
            \array_slice($matching, $offset, KsefPurchaseInvoiceQuery::PAGE_SIZE),
            \count($matching) > $offset + KsefPurchaseInvoiceQuery::PAGE_SIZE,
            false,
            $to,
        );
    }

    public function downloadInvoice(KsefSession $session, string $ksefNumber): string
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);
        $failure = $this->downloadFailures[$ksefNumber] ?? null;
        if (null !== $failure) {
            unset($this->downloadFailures[$ksefNumber]);

            throw $failure;
        }

        foreach ($this->invoices[$session->contextNip] ?? [] as $invoice) {
            if ($invoice['metadata']->ksefNumber === $ksefNumber) {
                $this->downloaded[] = $ksefNumber;

                return isset($this->corrupted[$ksefNumber]) ? $invoice['xml'].' ' : $invoice['xml'];
            }
        }

        throw KsefRequestFailedException::unexpectedStatus('invoice download', 404, 'Faktura nie istnieje.', []);
    }

    public function endSession(KsefSession $session): void
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);
        $this->endedSessions[] = $session->referenceNumber;
    }

    private function throwQueuedFailure(): void
    {
        $failure = array_shift($this->failures);
        if (null !== $failure) {
            throw $failure;
        }
    }

    private function assertOpen(KsefSession $session): void
    {
        if (\in_array($session->referenceNumber, $this->endedSessions, true)) {
            throw KsefRequestFailedException::unexpectedStatus('session', 401, 'Sesja została zakończona.', []);
        }
    }
}
