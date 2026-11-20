<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\ImportKsefInvoices;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Query\ListStoredExternalIds\ListStoredExternalIdsQuery;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Ksef\Application\Command\AdvanceKsefSyncCursor\AdvanceKsefSyncCursorCommand;
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
use App\Ksef\Domain\Model\KsefInvoiceMetadata;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealedSecretView;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\SecretKind;
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
 * - Each new invoice is downloaded alone, checked against the metadata's SHA-256, parsed and stored in
 *   Expenses through ImportPurchaseInvoiceCommand with its XML, then the cursor moves to its
 *   permanent-storage date: one flush per invoice, so an interrupted run loses nothing. After a
 *   complete window the cursor moves to the high-water mark (or the window's end, if earlier).
 * - An invoice the parser or Expenses refuses is skipped and named in the run's status.
 * - Stops cleanly after the time budget; the next run continues from the cursor.
 * - KSeF failures are recorded on the connection, never thrown: a 429 ends the run and holds runs
 *   until Retry-After; a rejected or unreadable token makes the connection `credentials_rejected`.
 */
final readonly class ImportKsefInvoicesJobHandler implements JobHandlerInterface
{
    public const int TIME_BUDGET_SECONDS = 45 * 60;

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

        $progress = new ImportProgress($startedAt, $startedAt->modify(\sprintf('+%d seconds', self::TIME_BUDGET_SECONDS)));
        try {
            /** @var RevealedSecretView|null $token */
            $token = $this->queryBus->ask(new RevealOrganizationSecretQuery($job->organizationId, $secretId->toRfc4122(), SecretKind::KsefToken->value));
            if (null === $token) {
                $progress->fail(KsefErrorCode::TokenUnreadable, 'The saved KSeF token is missing: save it again.');
            } else {
                $session = $this->ksef->authenticateWithKsefToken($nip->value, $token->value);
                try {
                    $this->importFrom($session, $connection->syncFrom(new \DateTimeZone($organization->getTimezone())), $job->organizationId, $progress);
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
        ));
        $this->logger->info('KSeF import finished: {imported} imported, {skipped} skipped.', [
            'organization_id' => $job->organizationId,
            'imported' => $progress->imported,
            'skipped' => $progress->skipped,
            'error' => $progress->recordedError()?->value,
        ]);
    }

    /**
     * Windows of at most 100 days from the cursor up to the start of the run. Returns when it caught
     * up with KSeF's high-water mark, or when the run must stop (the progress says why).
     *
     * @throws KsefException
     */
    private function importFrom(KsefSession $session, \DateTimeImmutable $from, string $organizationId, ImportProgress $progress): void
    {
        while ($from < $progress->startedAt) {
            $windowEnd = min($from->modify(\sprintf('+%d days', KsefPurchaseInvoiceQuery::MAX_RANGE_DAYS)), $progress->startedAt);
            $query = new KsefPurchaseInvoiceQuery($from, $windowEnd);

            while (true) {
                if ($this->outOfTime($progress)) {
                    return;
                }
                $page = $this->ksef->queryPurchaseInvoiceMetadata($session, $query);
                if (!$this->importPage($session, $page, $organizationId, $progress)) {
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
            $this->advanceCursor($organizationId, $reached);
            if ($reached < $windowEnd) {
                return;
            }
            $from = $windowEnd;
        }
    }

    /**
     * Imports a page's new FA(3) invoices in order. False when the run must stop (time budget, or an
     * invoice that does not match its hash).
     *
     * @throws KsefException
     */
    private function importPage(KsefSession $session, KsefInvoiceMetadataPage $page, string $organizationId, ImportProgress $progress): bool
    {
        $fa3Numbers = array_values(array_map(
            static fn (KsefInvoiceMetadata $metadata): string => $metadata->ksefNumber,
            array_filter($page->invoices, static fn (KsefInvoiceMetadata $metadata): bool => $metadata->formCode->isFa3()),
        ));
        /** @var list<string> $stored */
        $stored = [] === $fa3Numbers ? [] : $this->queryBus->ask(new ListStoredExternalIdsQuery($organizationId, PurchaseInvoiceSource::Ksef->value, $fa3Numbers));

        foreach ($page->invoices as $metadata) {
            if ($this->outOfTime($progress)) {
                return false;
            }
            if (!$metadata->formCode->isFa3()) {
                ++$progress->skipped;
                $this->advanceCursor($organizationId, $metadata->permanentStorageDate);
                continue;
            }
            if (\in_array($metadata->ksefNumber, $stored, true)) {
                continue;
            }

            $xml = $this->ksef->downloadInvoice($session, $metadata->ksefNumber);
            if (!hash_equals($metadata->invoiceHash, base64_encode(hash('sha256', $xml, true)))) {
                $progress->fail(KsefErrorCode::RequestFailed, \sprintf('The invoice %s downloaded from KSeF does not match its SHA-256 hash; the next run tries again.', $metadata->ksefNumber));

                return false;
            }

            $this->importInvoice($metadata, $xml, $organizationId, $progress);
            $this->advanceCursor($organizationId, $metadata->permanentStorageDate);
        }

        return true;
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

    private function advanceCursor(string $organizationId, \DateTimeImmutable $cursor): void
    {
        $this->commandBus->handle(new AdvanceKsefSyncCursorCommand($organizationId, $cursor->format('Y-m-d\TH:i:s.uP')));
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
