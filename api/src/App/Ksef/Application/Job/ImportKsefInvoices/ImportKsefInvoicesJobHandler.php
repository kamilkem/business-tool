<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ImportKsefInvoices;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Query\ListStoredExternalIds\ListStoredExternalIdsQuery;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Ksef\Application\Command\AdvanceKsefSyncCursor\AdvanceKsefSyncCursorCommand;
use App\Ksef\Application\Command\FinishKsefInvoiceExport\FinishKsefInvoiceExportCommand;
use App\Ksef\Application\Command\RecordKsefInvoiceExport\RecordKsefInvoiceExportCommand;
use App\Ksef\Application\Command\RecordKsefSyncRun\RecordKsefSyncRunCommand;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Exception\UnsupportedInvoiceFormException;
use App\Ksef\Domain\Fa3InvoiceParserInterface;
use App\Ksef\Domain\KsefClientInterface;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Model\KsefImportMethod;
use App\Ksef\Domain\Model\KsefInvoiceExport;
use App\Ksef\Domain\Model\KsefInvoiceExportState;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealedSecretView;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\SecretKind;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * One organization's KSeF import run (ADR 0023 §4–5, ADR 0024 §3, ADR 0025 §3). The job bus has
 * switched the tenant to the organization.
 *
 * - Runs only for an organization still in Poland with a valid NIP and a connection that may sync
 *   (active, a token, KSeF's Retry-After passed); otherwise it does nothing.
 * - Authenticates with the stored token, then queries purchase invoice metadata from the cursor in
 *   windows of at most 100 days up to the start of the run, page by page (`hasMore`), starting again
 *   from the last record when the result is truncated. Invoices of another form than FA(3) are
 *   skipped (counted); invoices already in Expenses are passed over by their KSeF number.
 * - Only invoices issued on or after the import's start date are imported (ADR 0038): one issued
 *   earlier but stored in KSeF later is passed over, and the cursor moves past it.
 * - Each new invoice is downloaded alone, checked against the metadata's SHA-256, parsed and stored in
 *   Expenses through ImportPurchaseInvoiceCommand with its XML, then the cursor moves to its
 *   permanent-storage date: one flush per invoice, so an interrupted run loses nothing. After a
 *   complete window the cursor moves to the high-water mark (or the window's end, if earlier).
 * - Once the run has seen more than 64 invoices waiting, it imports from the cursor with package
 *   exports instead (ADR 0039): the export is kept on the connection, the package waited for a few
 *   minutes, then decrypted and imported with the same rules, invoice by invoice. A package not
 *   ready yet, or one the run had no time to finish, is taken up again by the next run.
 * - The run records how it fetched the invoices and how many it knows are still waiting.
 * - An invoice the parser or Expenses refuses is skipped and named in the run's status.
 * - Stops cleanly after the time budget; the next run continues from the cursor.
 * - KSeF failures are recorded on the connection, never thrown: a 429 ends the run and holds runs
 *   until Retry-After; a rejected or unreadable token makes the connection `credentials_rejected`.
 */
final readonly class ImportKsefInvoicesJobHandler implements JobHandlerInterface
{
    public const int TIME_BUDGET_SECONDS = 45 * 60;
    /** How long a run waits for KSeF to build a package before leaving it to the next run (ADR 0039 §4). */
    public const int EXPORT_WAIT_SECONDS = 5 * 60;
    /** KSeF numbers per lookup of the invoices already in Expenses. */
    private const int STORED_LOOKUP_CHUNK = 1000;

    public function __construct(
        private KsefConnectionRepositoryInterface $connections,
        private KsefClientInterface $ksef,
        private Fa3InvoiceParserInterface $parser,
        private TenantContextInterface $tenantContext,
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportKsefInvoicesJob $job): void
    {
        $organization = $this->tenantContext->getOrganization();
        if ($organization->getId()->toRfc4122() !== $job->organizationId) {
            throw new UnrecoverableMessageHandlingException('The KSeF import runs only inside its own organization.');
        }

        try {
            $nip = KsefAccess::nipFor($organization->getCountryCode(), $organization->getTaxId());
        } catch (KsefNotAvailableException $e) {
            $this->logger->info('KSeF import skipped: {reason}', ['organization_id' => $job->organizationId, 'reason' => $e->getMessage()]);

            return;
        }

        $startedAt = $this->clock->now();
        $connection = $this->connections->findCurrent();
        $secretId = $connection?->getSecretId();
        if (null === $connection || null === $secretId || !$connection->canSync($startedAt)) {
            $this->logger->info('KSeF import skipped: the connection may not sync now.', ['organization_id' => $job->organizationId]);

            return;
        }

        $timeZone = new \DateTimeZone($organization->getTimezone());
        $progress = new ImportProgress($startedAt, $startedAt->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS)), $connection->syncFrom($timeZone));
        try {
            /** @var RevealedSecretView|null $token */
            $token = $this->queryBus->ask(new RevealOrganizationSecretQuery($job->organizationId, $secretId->toRfc4122(), SecretKind::KsefToken->value));
            if (null === $token) {
                $progress->fail(KsefErrorCode::TokenUnreadable, 'The saved KSeF token is missing: save it again.');
            } else {
                $session = $this->ksef->authenticateWithKsefToken($nip->value, $token->value);
                try {
                    $this->import($session, $connection->effectiveSyncStartDate($timeZone), $connection->getPendingExport(), $job->organizationId, $progress);
                } finally {
                    $this->endSession($session);
                }
            }
        } catch (SecretUnreadableException) {
            $progress->fail(KsefErrorCode::TokenUnreadable, 'The saved KSeF token cannot be decrypted: save it again.');
        } catch (KsefCredentialsRejectedException $e) {
            $progress->fail(KsefErrorCode::CredentialsRejected, $e->getMessage());
        } catch (KsefRateLimitedException $e) {
            $retryNotBefore = null === $e->retryAfterSeconds ? null : $this->clock->now()->modify(\sprintf('+%d seconds', $e->retryAfterSeconds));
            $progress->fail(KsefErrorCode::RateLimited, $e->getMessage(), $retryNotBefore);
        } catch (KsefUnavailableException $e) {
            $progress->fail(KsefErrorCode::Unavailable, $e->getMessage());
        } catch (KsefRequestFailedException $e) {
            $progress->fail(KsefErrorCode::RequestFailed, $e->getMessage());
        }

        $this->commandBus->handle(new RecordKsefSyncRunCommand(
            $job->organizationId,
            $startedAt->format(\DATE_ATOM),
            $progress->imported,
            $progress->skipped,
            $progress->recordedError()?->value,
            $progress->recordedErrorMessage(),
            $progress->retryNotBefore?->format(\DATE_ATOM),
            $progress->method->value,
            $progress->backlogCount(),
            $progress->backlogIsMinimum(),
        ));
        $this->logger->info('KSeF import finished: {imported} imported, {skipped} skipped.', [
            'organization_id' => $job->organizationId,
            'imported' => $progress->imported,
            'skipped' => $progress->skipped,
            'error' => $progress->recordedError()?->value,
            'method' => $progress->method->value,
            'backlog' => $progress->backlogCount(),
        ]);
    }

    /**
     * The run's import from the cursor: first a pending export whose range holds the cursor (another
     * one is given up), then single downloads, which switch to exports once more than 64 invoices
     * are waiting (ADR 0039 §1, §6).
     *
     * @throws KsefException
     */
    private function import(KsefSession $session, BusinessDate $issuedFrom, ?KsefInvoiceExport $pending, string $organizationId, ImportProgress $progress): void
    {
        if (null !== $pending) {
            if (!$pending->covers($progress->cursor)) {
                $this->finishExport($organizationId, $pending);
            } elseif (!$this->importExport($session, $pending, $issuedFrom, $organizationId, $progress)) {
                return;
            }
        }

        $this->downloadFrom($session, $issuedFrom, $organizationId, $progress);
    }

    /**
     * Single downloads in windows of at most 100 days from the cursor up to the start of the run.
     * Returns when it caught up with KSeF's high-water mark, when the run must stop (the progress
     * says why), or after handing over to exports.
     *
     * @throws KsefException
     */
    private function downloadFrom(KsefSession $session, BusinessDate $issuedFrom, string $organizationId, ImportProgress $progress): void
    {
        $from = $progress->cursor;
        // Waiting invoices this method has met; a package imported before does not count.
        $waitingForDownload = 0;
        while ($from < $progress->startedAt) {
            $windowEnd = min($from->modify(\sprintf('+%d days', KsefPurchaseInvoiceQuery::MAX_RANGE_DAYS)), $progress->startedAt);
            $query = new KsefPurchaseInvoiceQuery($from, $windowEnd);

            while (true) {
                if ($this->outOfTime($progress)) {
                    return;
                }
                $page = $this->ksef->queryPurchaseInvoiceMetadata($session, $query);
                $stored = $this->storedNumbers($page->invoices, $organizationId);
                $waiting = self::countWaiting($page->invoices, $stored, $issuedFrom);
                if ($waitingForDownload + $waiting > KsefImportMethod::SINGLE_DOWNLOAD_LIMIT) {
                    $this->exportFrom($session, $issuedFrom, $organizationId, $progress);

                    return;
                }
                $waitingForDownload += $waiting;
                $progress->waiting += $waiting;

                $xmlOf = fn (KsefInvoiceMetadata $metadata): string => $this->ksef->downloadInvoice($session, $metadata->ksefNumber);
                if (!$this->importInvoices($page->invoices, $stored, $issuedFrom, $xmlOf, $organizationId, $progress)) {
                    return;
                }

                if ($page->isTruncated) {
                    $last = $page->invoices[array_key_last($page->invoices) ?? throw KsefRequestFailedException::malformedResponse('invoice metadata query', 'a truncated result without records')];
                    if ($last->permanentStorageDate <= $query->from) {
                        throw KsefRequestFailedException::malformedResponse('invoice metadata query', 'a truncated result that does not move forward');
                    }
                    $query = new KsefPurchaseInvoiceQuery($last->permanentStorageDate, $windowEnd);
                } elseif ($page->hasMore) {
                    $query = $query->nextPage();
                } else {
                    break;
                }
            }

            // The window is complete up to the high-water mark: below it the results no longer change.
            $hwm = $page->permanentStorageHwmDate;
            if (null === $hwm) {
                return;
            }
            $reached = min($hwm, $windowEnd);
            $this->advanceCursor($organizationId, $reached, $progress);
            if ($reached < $windowEnd) {
                $progress->caughtUp = true;

                return;
            }
            $from = $windowEnd;
        }
        $progress->caughtUp = true;
    }

    /**
     * Package exports from the cursor up to the start of the run, one window of at most 100 days each
     * (ADR 0039 §2).
     *
     * @throws KsefException
     */
    private function exportFrom(KsefSession $session, BusinessDate $issuedFrom, string $organizationId, ImportProgress $progress): void
    {
        $progress->method = KsefImportMethod::Export;
        while (true) {
            $from = KsefInvoiceExport::wholeSecond($progress->cursor);
            $to = KsefInvoiceExport::wholeSecond(min($from->modify(\sprintf('+%d days', KsefPurchaseInvoiceQuery::MAX_RANGE_DAYS)), $progress->startedAt));
            if ($to <= $from) {
                $progress->caughtUp = true;

                return;
            }
            if ($this->outOfTime($progress)) {
                return;
            }

            $keyId = Uuid::v7()->toRfc4122();
            $referenceNumber = $this->ksef->startPurchaseInvoiceExport($session, new KsefPurchaseInvoiceQuery($from, $to), $keyId);
            $export = new KsefInvoiceExport($referenceNumber, $keyId, $from, $to, $this->clock->now());
            $this->commandBus->handle(new RecordKsefInvoiceExportCommand(
                $organizationId,
                $referenceNumber,
                $keyId,
                $export->from->format(\DATE_ATOM),
                $export->to->format(\DATE_ATOM),
                $export->startedAt->format(\DATE_ATOM),
            ));
            $this->logger->info('KSeF export {reference_number} requested from {from} to {to}.', [
                'organization_id' => $organizationId,
                'reference_number' => $referenceNumber,
                'from' => $export->from->format(\DATE_ATOM),
                'to' => $export->to->format(\DATE_ATOM),
            ]);

            if (!$this->importExport($session, $export, $issuedFrom, $organizationId, $progress)) {
                return;
            }
        }
    }

    /**
     * Waits for the export's package and imports it (ADR 0039 §4–6). True when the import goes on
     * from the cursor (the package is done, or it expired and needs a new export); false when the run
     * stops: the package is not ready, the run caught up or ran out of time, or the export failed.
     *
     * @throws KsefException
     */
    private function importExport(KsefSession $session, KsefInvoiceExport $export, BusinessDate $issuedFrom, string $organizationId, ImportProgress $progress): bool
    {
        $progress->method = KsefImportMethod::Export;
        $secondsLeft = $progress->deadline->getTimestamp() - $this->clock->now()->getTimestamp();
        $status = $this->ksef->getPurchaseInvoiceExport($session, $export->referenceNumber, max(0, min(self::EXPORT_WAIT_SECONDS, $secondsLeft)));

        switch ($status->state) {
            case KsefInvoiceExportState::InProgress:
                $progress->exportPending = true;

                return false;
            case KsefInvoiceExportState::Expired:
                $this->finishExport($organizationId, $export);

                return true;
            case KsefInvoiceExportState::NothingToExport:
                $this->finishExport($organizationId, $export);
                $progress->caughtUp = true;

                return false;
            case KsefInvoiceExportState::Failed:
                $this->finishExport($organizationId, $export);
                $progress->fail(
                    $status->transient ? KsefErrorCode::Unavailable : KsefErrorCode::RequestFailed,
                    \sprintf('The KSeF export %s failed: %s; the next run requests a new one.', $export->referenceNumber, $status->failure ?? 'no reason given'),
                );

                return false;
            case KsefInvoiceExportState::Ready:
                break;
        }
        $package = $status->package ?? throw KsefRequestFailedException::malformedResponse('invoice export status', 'a finished export without a package');

        try {
            $contents = $this->ksef->openInvoicePackage($session, $export->keyId, $package);
        } catch (KsefRequestFailedException $e) {
            $this->finishExport($organizationId, $export);

            throw $e;
        }
        try {
            // Records below the cursor were done by an earlier run that stopped in this package.
            $cursor = KsefInvoiceExport::wholeSecond($progress->cursor);
            $records = array_values(array_filter($contents->invoices(), static fn (KsefInvoiceMetadata $metadata): bool => $metadata->permanentStorageDate >= $cursor));
            $stored = $this->storedNumbers($records, $organizationId);
            $progress->waiting += self::countWaiting($records, $stored, $issuedFrom);

            $completed = $this->importInvoices($records, $stored, $issuedFrom, static fn (KsefInvoiceMetadata $metadata): string => $contents->xmlOf($metadata->ksefNumber), $organizationId, $progress);
        } catch (KsefRequestFailedException $e) {
            $this->finishExport($organizationId, $export);

            throw $e;
        } finally {
            $contents->close();
        }
        if (!$completed) {
            // Out of time: the next run imports the rest of this package. A mismatch: a new export.
            if (null !== $progress->error) {
                $this->finishExport($organizationId, $export);
            }

            return false;
        }

        $this->finishExport($organizationId, $export);
        if ($package->isTruncated) {
            $last = $package->lastPermanentStorageDate ?? throw KsefRequestFailedException::malformedResponse('invoice export status', 'a truncated package without lastPermanentStorageDate');
            if ($last <= $export->from) {
                throw KsefRequestFailedException::malformedResponse('invoice export status', 'a truncated package that does not move forward');
            }
            $this->advanceCursor($organizationId, $last, $progress);

            return true;
        }
        $reached = min($package->permanentStorageHwmDate ?? $export->to, $export->to);
        $this->advanceCursor($organizationId, $reached, $progress);
        if ($reached < $export->to) {
            $progress->caughtUp = true;

            return false;
        }

        return true;
    }

    /**
     * Imports the new FA(3) invoices among `$records` issued on or after `$issuedFrom`, in order, with
     * the XML `$xmlOf` gives. False when the run must stop (time budget, or an invoice that does not
     * match its hash).
     *
     * @param list<KsefInvoiceMetadata>             $records
     * @param list<string>                          $stored  KSeF numbers already in Expenses
     * @param \Closure(KsefInvoiceMetadata): string $xmlOf
     *
     * @throws KsefException
     */
    private function importInvoices(array $records, array $stored, BusinessDate $issuedFrom, \Closure $xmlOf, string $organizationId, ImportProgress $progress): bool
    {
        foreach ($records as $metadata) {
            if ($this->outOfTime($progress)) {
                return false;
            }
            if ($metadata->issueDate->isBefore($issuedFrom)) {
                $this->advanceCursor($organizationId, $metadata->permanentStorageDate, $progress);
                continue;
            }
            if (!$metadata->formCode->isFa3()) {
                ++$progress->skipped;
                $this->advanceCursor($organizationId, $metadata->permanentStorageDate, $progress);
                continue;
            }
            if (\in_array($metadata->ksefNumber, $stored, true)) {
                continue;
            }

            $xml = $xmlOf($metadata);
            if (!hash_equals($metadata->invoiceHash, base64_encode(hash('sha256', $xml, true)))) {
                $progress->fail(KsefErrorCode::RequestFailed, \sprintf('The invoice %s downloaded from KSeF does not match its SHA-256 hash; the next run tries again.', $metadata->ksefNumber));

                return false;
            }

            $this->importInvoice($metadata, $xml, $organizationId, $progress);
            ++$progress->handled;
            $this->advanceCursor($organizationId, $metadata->permanentStorageDate, $progress);
        }

        return true;
    }

    /**
     * The FA(3) invoices among `$records` that are already in Expenses.
     *
     * @param list<KsefInvoiceMetadata> $records
     *
     * @return list<string>
     */
    private function storedNumbers(array $records, string $organizationId): array
    {
        $fa3Numbers = array_values(array_map(
            static fn (KsefInvoiceMetadata $metadata): string => $metadata->ksefNumber,
            array_filter($records, static fn (KsefInvoiceMetadata $metadata): bool => $metadata->formCode->isFa3()),
        ));
        $stored = [];
        foreach (array_chunk($fa3Numbers, self::STORED_LOOKUP_CHUNK) as $chunk) {
            /** @var list<string> $found */
            $found = $this->queryBus->ask(new ListStoredExternalIdsQuery($organizationId, PurchaseInvoiceSource::Ksef->value, $chunk));
            array_push($stored, ...$found);
        }

        return $stored;
    }

    /**
     * The waiting invoices among `$records`: FA(3), issued on or after the start date, not in Expenses.
     *
     * @param list<KsefInvoiceMetadata> $records
     * @param list<string>              $stored
     */
    private static function countWaiting(array $records, array $stored, BusinessDate $issuedFrom): int
    {
        return \count(array_filter(
            $records,
            static fn (KsefInvoiceMetadata $metadata): bool => $metadata->formCode->isFa3()
                && !$metadata->issueDate->isBefore($issuedFrom)
                && !\in_array($metadata->ksefNumber, $stored, true),
        ));
    }

    private function finishExport(string $organizationId, KsefInvoiceExport $export): void
    {
        $this->commandBus->handle(new FinishKsefInvoiceExportCommand($organizationId, $export->referenceNumber));
    }

    private function importInvoice(KsefInvoiceMetadata $metadata, string $xml, string $organizationId, ImportProgress $progress): void
    {
        try {
            $details = $this->parser->parse($xml);
            $this->commandBus->handle(ImportPurchaseInvoiceCommand::of(
                $organizationId,
                Uuid::v7()->toRfc4122(),
                PurchaseInvoiceSource::Ksef->value,
                $metadata->ksefNumber,
                $details,
                $xml,
                $metadata->formCode->systemCode,
            ));
            ++$progress->imported;
        } catch (PurchaseInvoiceAlreadyImportedException) {
            // Stored meanwhile (e.g. by an earlier, interrupted run): nothing to do.
        } catch (UnsupportedInvoiceFormException|InvalidFa3InvoiceException|InvalidPurchaseInvoiceException $e) {
            $progress->skipUnreadable($metadata->ksefNumber, $e->getMessage());
            $this->logger->warning('KSeF invoice {ksef_number} skipped: {reason}', [
                'organization_id' => $organizationId,
                'ksef_number' => $metadata->ksefNumber,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function advanceCursor(string $organizationId, \DateTimeImmutable $cursor, ImportProgress $progress): void
    {
        $this->commandBus->handle(new AdvanceKsefSyncCursorCommand($organizationId, $cursor->format('Y-m-d\TH:i:s.uP')));
        if ($cursor > $progress->cursor) {
            $progress->cursor = $cursor;
        }
    }

    private function outOfTime(ImportProgress $progress): bool
    {
        return $this->clock->now() >= $progress->deadline;
    }

    /** A failure to revoke the session does not fail the run: it expires by itself. */
    private function endSession(KsefSession $session): void
    {
        try {
            $this->ksef->endSession($session);
        } catch (KsefException) {
        }
    }
}
