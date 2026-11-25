<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Ksef;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\KsefClientInterface;
use App\Ksef\Domain\KsefInvoicePackageContentsInterface;
use App\Ksef\Domain\Model\KsefFormCode;
use App\Ksef\Domain\Model\KsefInvoiceExportStatus;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefInvoicePackage;
use App\Ksef\Domain\Model\KsefInvoicePackagePart;
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

    /**
     * Package exports requested, by reference number (ADR 0039).
     *
     * @var array<string, array{nip: string, from: \DateTimeImmutable, to: \DateTimeImmutable, keyId: string}>
     */
    public array $exports = [];

    /** How many status checks an export stays in progress before its package is ready. */
    public int $exportReadyAfterChecks = 0;

    /** When set, a package holds at most this many invoices and says it is truncated. */
    public ?int $packageLimit = null;

    /** @var array<string, KsefInvoiceExportStatus> reference number => status to answer instead */
    private array $exportStatuses = [];

    /** @var array<string, int> reference number => status checks so far */
    private array $exportChecks = [];

    /** @var array<string, list<KsefInvoiceMetadata>> package URL => its invoices */
    private array $packages = [];

    /** @var list<string> reference numbers whose packages were opened */
    public array $openedPackages = [];

    /** Opened packages not closed yet. */
    public int $openPackages = 0;

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
        $matching = $this->matching($session->contextNip, $query->from, $to);

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

    /** The status of this export is this, whatever its package (expired, failed, ...). */
    public function answerExportWith(string $referenceNumber, KsefInvoiceExportStatus $status): void
    {
        $this->exportStatuses[$referenceNumber] = $status;
    }

    public function startPurchaseInvoiceExport(KsefSession $session, KsefPurchaseInvoiceQuery $range, string $keyId): string
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);
        $reference = \sprintf('export-%d', \count($this->exports) + 1);
        $this->exports[$reference] = ['nip' => $session->contextNip, 'from' => $range->from, 'to' => $range->to, 'keyId' => $keyId];

        return $reference;
    }

    public function getPurchaseInvoiceExport(KsefSession $session, string $referenceNumber, int $waitSeconds): KsefInvoiceExportStatus
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);
        if (isset($this->exportStatuses[$referenceNumber])) {
            return $this->exportStatuses[$referenceNumber];
        }
        $export = $this->exports[$referenceNumber] ?? null;
        if (null === $export) {
            return KsefInvoiceExportStatus::expired();
        }
        $checks = $this->exportChecks[$referenceNumber] = ($this->exportChecks[$referenceNumber] ?? 0) + 1;
        if ($checks <= $this->exportReadyAfterChecks) {
            return KsefInvoiceExportStatus::inProgress();
        }

        $to = null === $this->permanentStorageHwmDate ? $export['to'] : min($export['to'], $this->permanentStorageHwmDate);
        $matching = $this->matching($export['nip'], $export['from'], $to);
        $truncated = null !== $this->packageLimit && \count($matching) > $this->packageLimit;
        if ($truncated) {
            $matching = \array_slice($matching, 0, (int) $this->packageLimit);
        }
        $url = 'https://storage.example/'.$referenceNumber.'-'.$checks;
        $this->packages[$url] = $matching;
        $last = [] === $matching ? null : $matching[\count($matching) - 1]->permanentStorageDate;

        return KsefInvoiceExportStatus::ready(new KsefInvoicePackage(
            \count($matching),
            [new KsefInvoicePackagePart(1, $url, 'fixture-hash', 'fixture-encrypted-hash')],
            $truncated,
            $truncated ? $last : null,
            $to,
        ));
    }

    public function openInvoicePackage(KsefSession $session, string $keyId, KsefInvoicePackage $package): KsefInvoicePackageContentsInterface
    {
        $this->throwQueuedFailure();
        $invoices = [];
        foreach ($package->parts as $part) {
            array_push($invoices, ...($this->packages[$part->url] ?? throw KsefRequestFailedException::malformedResponse('invoice package', 'unknown part')));
        }
        $reference = array_key_first(array_filter($this->exports, static fn (array $export): bool => $export['keyId'] === $keyId));
        if (null === $reference) {
            throw KsefRequestFailedException::malformedResponse('invoice package', 'part 1 does not decrypt with the export key');
        }
        $this->openedPackages[] = $reference;
        ++$this->openPackages;
        $xml = [];
        foreach ($this->invoices[$session->contextNip] ?? [] as $invoice) {
            $number = $invoice['metadata']->ksefNumber;
            $xml[$number] = isset($this->corrupted[$number]) ? $invoice['xml'].' ' : $invoice['xml'];
        }

        return new InMemoryInvoicePackage($invoices, $xml, function (): void {
            --$this->openPackages;
        });
    }

    public function endSession(KsefSession $session): void
    {
        $this->throwQueuedFailure();
        $this->assertOpen($session);
        $this->endedSessions[] = $session->referenceNumber;
    }

    /** @return list<KsefInvoiceMetadata> the buyer's invoices stored in [from, to), oldest first */
    private function matching(string $nip, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $matching = array_values(array_filter(
            array_map(static fn (array $invoice): KsefInvoiceMetadata => $invoice['metadata'], $this->invoices[$nip] ?? []),
            static fn (KsefInvoiceMetadata $metadata): bool => $metadata->permanentStorageDate >= $from && $metadata->permanentStorageDate < $to,
        ));
        usort($matching, static fn (KsefInvoiceMetadata $a, KsefInvoiceMetadata $b): int => $a->permanentStorageDate <=> $b->permanentStorageDate);

        return $matching;
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
