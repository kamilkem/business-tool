<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceCommand;
use App\Expenses\Application\Command\ImportPurchaseInvoice\ImportPurchaseInvoiceHandler;
use App\Expenses\Application\SellerCategorySuggestion;
use App\Expenses\Application\Query\ListStoredExternalIds\ListStoredExternalIdsHandler;
use App\Expenses\Application\Query\ListStoredExternalIds\ListStoredExternalIdsQuery;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Ksef\Application\Command\AdvanceKsefSyncCursor\AdvanceKsefSyncCursorCommand;
use App\Ksef\Application\Command\AdvanceKsefSyncCursor\AdvanceKsefSyncCursorHandler;
use App\Ksef\Application\Command\FinishKsefInvoiceExport\FinishKsefInvoiceExportCommand;
use App\Ksef\Application\Command\FinishKsefInvoiceExport\FinishKsefInvoiceExportHandler;
use App\Ksef\Application\Command\RecordKsefInvoiceExport\RecordKsefInvoiceExportCommand;
use App\Ksef\Application\Command\RecordKsefInvoiceExport\RecordKsefInvoiceExportHandler;
use App\Ksef\Application\Command\RecordKsefSyncRun\RecordKsefSyncRunCommand;
use App\Ksef\Application\Command\RecordKsefSyncRun\RecordKsefSyncRunHandler;
use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJobHandler;
use App\Ksef\Application\Job\ImportKsefInvoices\ImportProgress;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Fa3InvoiceParserInterface;
use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Model\KsefConnectionStatus;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Model\KsefFormCode;
use App\Ksef\Domain\Model\KsefImportMethod;
use App\Ksef\Domain\Model\KsefInvoiceExport;
use App\Ksef\Domain\Model\KsefInvoiceExportStatus;
use App\Ksef\Infrastructure\Fa3\Fa3InvoiceParser;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealedSecretView;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\Ksef\InMemoryKsefClient;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Expenses\Application\InMemoryExpenseCategoryRepository;
use App\Tests\Unit\Expenses\Application\InMemoryPurchaseInvoiceRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * A bar in Poland (Europe/Warsaw) whose KSeF connection was created on 2026-11-10, with a working
 * token. KSeF is InMemoryKsefClient; the parser is the real FA(3) parser. The command bus records
 * every command and passes it on to the real Expenses and KSeF handlers over in-memory repositories,
 * so the cursor and the stored invoices can be checked after each run. The job bus has switched the
 * tenant (InMemoryTenantContext).
 */
#[CoversClass(ImportKsefInvoicesJobHandler::class)]
#[CoversClass(ImportKsefInvoicesJob::class)]
#[CoversClass(ImportProgress::class)]
#[CoversClass(ImportPurchaseInvoiceHandler::class)]
#[CoversClass(ImportPurchaseInvoiceCommand::class)]
#[CoversClass(ListStoredExternalIdsHandler::class)]
#[CoversClass(AdvanceKsefSyncCursorHandler::class)]
#[CoversClass(RecordKsefSyncRunHandler::class)]
#[CoversClass(RecordKsefInvoiceExportHandler::class)]
#[CoversClass(FinishKsefInvoiceExportHandler::class)]
final class ImportKsefInvoicesJobHandlerTest extends TestCase
{
    private const string NOW = '2026-11-20 21:00:00';
    private const string NIP = '1111111111';
    private const string TOKEN = 'fake-ksef-token|nip-1111111111|not-a-real-token';
    /** The first run starts on 1 October 00:00 in Warsaw (the connection was created in November). */
    private const string FIRST_RUN_FROM = '2026-09-30 22:00:00 UTC';

    private MockClock $clock;
    private InMemoryKsefClient $ksef;
    private InMemoryKsefConnectionRepository $connections;
    private InMemoryPurchaseInvoiceRepository $invoices;
    private InMemoryTenantContext $tenantContext;
    private RecordingCommandBus $commandBus;
    private RecordingLogger $logger;
    private Organization $organization;
    private KsefConnection $connection;
    private string $xml;
    private ?RevealedSecretView $token;
    private bool $tokenUnreadable = false;
    /** @var (\Closure(CommandInterface): void)|null runs after each command reached its handler */
    private ?\Closure $afterCommand = null;
    private Fa3InvoiceParserInterface $parser;
    private Uuid $ownerId;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->xml = (string) file_get_contents(__DIR__.'/../../../Fixtures/Ksef/invoice-fa3.xml');
        $this->ksef = new InMemoryKsefClient();
        $this->ksef->acceptToken(self::NIP, self::TOKEN);
        $this->parser = new Fa3InvoiceParser();

        $owner = UserMother::any('olivia@example.com');
        $this->ownerId = $owner->getId();
        $this->organization = new Organization(Uuid::v7(), 'Corner Pub', $owner, new \DateTimeImmutable('2026-01-05 09:00:00'), 'PL 111-111-11-11');
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);

        $this->connections = new InMemoryKsefConnectionRepository();
        $this->connection = KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', self::NIP, $owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-10 12:00:00'));
        $this->connection->assignOrganization($this->organization);
        $this->connections->add($this->connection);
        $this->token = new RevealedSecretView(Uuid::v7()->toRfc4122(), 'ksef_token', self::TOKEN);

        $this->invoices = new InMemoryPurchaseInvoiceRepository();
        $importInvoice = new ImportPurchaseInvoiceHandler($this->invoices, $this->tenantContext, $this->clock, new SellerCategorySuggestion($this->invoices, new InMemoryExpenseCategoryRepository()));
        $advanceCursor = new AdvanceKsefSyncCursorHandler($this->connections);
        $recordRun = new RecordKsefSyncRunHandler($this->connections);
        $recordExport = new RecordKsefInvoiceExportHandler($this->connections);
        $finishExport = new FinishKsefInvoiceExportHandler($this->connections);
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command) use ($importInvoice, $advanceCursor, $recordRun, $recordExport, $finishExport): void {
            match (true) {
                $command instanceof ImportPurchaseInvoiceCommand => $importInvoice($command),
                $command instanceof AdvanceKsefSyncCursorCommand => $advanceCursor($command),
                $command instanceof RecordKsefSyncRunCommand => $recordRun($command),
                $command instanceof RecordKsefInvoiceExportCommand => $recordExport($command),
                $command instanceof FinishKsefInvoiceExportCommand => $finishExport($command),
                default => throw new \LogicException(\sprintf('Unexpected command %s.', $command::class)),
            };
            if (null !== $this->afterCommand) {
                ($this->afterCommand)($command);
            }
        });
        $this->logger = new RecordingLogger();
    }

    public function test_it_imports_new_fa3_invoices_with_their_xml_and_records_the_run(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-2', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice('9999999999', 'KSEF-OTHER-BUYER', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-1', 'KSEF-2'], $this->storedNumbers());
        $invoice = $this->invoices->findByExternalId(PurchaseInvoiceSource::Ksef, 'KSEF-1') ?? self::fail('Invoice expected.');
        self::assertSame('FV/2026/11/0042', $invoice->getInvoiceNumber());
        self::assertSame(123000, $invoice->getGrossTotal());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        $document = $this->invoices->findByInvoiceId($invoice->getId()) ?? self::fail('Document expected.');
        self::assertSame($this->xml, $document->getContent());
        self::assertSame('FA (3)', $document->getFormat());

        self::assertSame(KsefConnectionStatus::Active, $this->connection->getStatus());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getLastSyncAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getLastSyncSucceededAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor(), 'A complete window moves the cursor to the high-water mark.');
        self::assertSame(2, $this->connection->getLastSyncImportedCount());
        self::assertSame(0, $this->connection->getLastSyncSkippedCount());
        self::assertNull($this->connection->getLastSyncError());
        self::assertSame(['in-memory-1'], $this->ksef->endedSessions);
    }

    public function test_it_moves_the_cursor_after_each_invoice_it_stores(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-2', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);

        $this->runJob();

        $commands = array_map(
            static fn (CommandInterface $command): string => match (true) {
                $command instanceof ImportPurchaseInvoiceCommand => 'import '.$command->externalId,
                $command instanceof AdvanceKsefSyncCursorCommand => 'cursor '.(new \DateTimeImmutable($command->cursor))->format('Y-m-d H:i'),
                $command instanceof RecordKsefSyncRunCommand => 'record',
                default => $command::class,
            },
            $this->commandBus->commands,
        );
        self::assertSame(['import KSEF-1', 'cursor 2026-11-03 10:00', 'import KSEF-2', 'cursor 2026-11-04 10:00', 'cursor 2026-11-20 21:00', 'record'], $commands);
    }

    public function test_it_starts_the_first_run_on_the_first_day_of_the_previous_month_before_the_connection(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-SEPTEMBER', new \DateTimeImmutable('2026-09-30 21:59:59 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-OCTOBER', new \DateTimeImmutable(self::FIRST_RUN_FROM), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-OCTOBER'], $this->storedNumbers());
    }

    public function test_it_starts_at_the_chosen_start_date_in_the_organizations_time_zone(): void
    {
        $this->chooseStartDate('2026-03-01');
        $this->ksef->addInvoice(self::NIP, 'KSEF-FEBRUARY', new \DateTimeImmutable('2026-02-28 22:59:59 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-MARCH', new \DateTimeImmutable('2026-02-28 23:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-NOVEMBER', new \DateTimeImmutable('2026-11-19 08:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-MARCH', 'KSEF-NOVEMBER'], $this->storedNumbers());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor());
    }

    public function test_it_imports_only_invoices_issued_on_or_after_the_start_date(): void
    {
        $this->chooseStartDate('2026-10-01');
        // Issued on 30 September, stored in KSeF on 1 October: September's, so not imported.
        $this->ksef->addInvoice(self::NIP, 'KSEF-ISSUED-SEPTEMBER', new \DateTimeImmutable('2026-10-01 08:00:00 UTC'), $this->xml, issueDate: BusinessDate::fromString('2026-09-30'));
        $this->ksef->addInvoice(self::NIP, 'KSEF-ISSUED-OCTOBER', new \DateTimeImmutable('2026-10-01 09:00:00 UTC'), $this->xml, issueDate: BusinessDate::fromString('2026-10-01'));

        $this->runJob();

        self::assertSame(['KSEF-ISSUED-OCTOBER'], $this->storedNumbers());
        self::assertSame(['KSEF-ISSUED-OCTOBER'], $this->ksef->downloaded, 'An invoice issued before the start is not downloaded.');
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
        self::assertSame(0, $this->connection->getLastSyncSkippedCount(), 'An invoice outside the range is not counted as skipped.');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor());
    }

    public function test_it_reads_again_from_an_earlier_start_date_without_storing_an_invoice_twice(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-MARCH', new \DateTimeImmutable('2026-03-10 08:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-OCTOBER', new \DateTimeImmutable('2026-10-10 08:00:00 UTC'), $this->xml);
        $this->runJob();
        self::assertSame(['KSEF-OCTOBER'], $this->storedNumbers());

        $this->chooseStartDate('2026-03-01');
        $this->clock->modify('+1 hour');
        $this->runJob();

        self::assertSame(['KSEF-MARCH', 'KSEF-OCTOBER'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
    }

    public function test_it_queries_windows_of_at_most_100_days_and_follows_pages(): void
    {
        // A cursor 250 days back: three windows. 260 invoices in one window: two pages.
        $this->chooseStartDate('2026-03-01');
        $this->connection->advanceSyncCursor(new \DateTimeImmutable('2026-03-15 00:00:00 UTC'));
        $this->ksef->addInvoice(self::NIP, 'KSEF-MARCH', new \DateTimeImmutable('2026-03-20 08:00:00 UTC'), $this->xml);
        for ($i = 0; $i < 260; ++$i) {
            $this->ksef->addInvoice(self::NIP, \sprintf('KSEF-JULY-%03d', $i), new \DateTimeImmutable('2026-07-01 08:00:00 UTC')->modify(\sprintf('+%d minutes', $i)), $this->xml);
        }
        $this->ksef->addInvoice(self::NIP, 'KSEF-NOVEMBER', new \DateTimeImmutable('2026-11-19 08:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertCount(262, $this->storedNumbers());
        self::assertSame(262, $this->connection->getLastSyncImportedCount());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor());
    }

    public function test_it_restarts_a_truncated_query_from_the_last_record(): void
    {
        $this->ksef->truncateAfter = 2;
        foreach (['KSEF-1', 'KSEF-2', 'KSEF-3', 'KSEF-4', 'KSEF-5'] as $i => $number) {
            $this->ksef->addInvoice(self::NIP, $number, new \DateTimeImmutable('2026-11-01 08:00:00 UTC')->modify(\sprintf('+%d hours', $i)), $this->xml);
        }

        $this->runJob();

        self::assertSame(['KSEF-1', 'KSEF-2', 'KSEF-3', 'KSEF-4', 'KSEF-5'], $this->storedNumbers());
        self::assertSame(5, $this->connection->getLastSyncImportedCount());
    }

    public function test_it_skips_invoices_already_stored_without_downloading_them(): void
    {
        // An earlier run stored KSEF-1 and was interrupted before it moved the cursor.
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-2', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);
        $this->commandBus->handle(ImportPurchaseInvoiceCommand::of(
            $this->organization->getId()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'ksef',
            'KSEF-1',
            $this->parser->parse($this->xml),
            $this->xml,
            'FA (3)',
        ));

        $this->runJob();

        self::assertSame(['KSEF-2'], $this->ksef->downloaded);
        self::assertSame(['KSEF-1', 'KSEF-2'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
        self::assertSame(0, $this->connection->getLastSyncSkippedCount());
    }

    public function test_it_skips_and_counts_invoices_of_another_form_without_downloading_them(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-FA2', new \DateTimeImmutable('2026-11-02 10:00:00 UTC'), '<Faktura/>', new KsefFormCode('FA (2)', '1-0E', 'FA'));
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-1'], $this->ksef->downloaded);
        self::assertSame(['KSEF-1'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
        self::assertSame(1, $this->connection->getLastSyncSkippedCount());
        self::assertNull($this->connection->getLastSyncError());
    }

    public function test_it_skips_an_invoice_the_parser_cannot_read_names_it_and_goes_on(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-BROKEN', new \DateTimeImmutable('2026-11-02 10:00:00 UTC'), 'not xml at all');
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-1'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
        self::assertSame(1, $this->connection->getLastSyncSkippedCount());
        self::assertSame(KsefErrorCode::InvoiceUnreadable, $this->connection->getLastSyncError());
        self::assertStringContainsString('KSEF-BROKEN', (string) $this->connection->getLastSyncErrorMessage());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getLastSyncSucceededAt(), 'The run completed.');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor(), 'The cursor moves past the unreadable invoice.');
        self::assertContains('KSeF invoice {ksef_number} skipped: {reason}', $this->logger->messages('warning'));
    }

    public function test_it_skips_an_invoice_expenses_refuses_names_it_and_goes_on(): void
    {
        $realParser = $this->parser;
        $this->parser = new class($realParser) implements Fa3InvoiceParserInterface {
            public function __construct(private readonly Fa3InvoiceParserInterface $parser)
            {
            }

            public function parse(string $xml): PurchaseInvoiceDetails
            {
                if (str_contains($xml, 'UNKNOWN-RATE')) {
                    return new PurchaseInvoiceDetails('FV/1', '3947150208', 'Hurtownia', BusinessDate::of(2026, 11, 2), PurchaseInvoiceKind::Regular, 'PLN', 100, 7, 107, [new PurchaseInvoiceVatAmount('7', 100, 7)]);
                }

                return $this->parser->parse($xml);
            }
        };
        $this->ksef->addInvoice(self::NIP, 'KSEF-UNKNOWN-RATE', new \DateTimeImmutable('2026-11-02 10:00:00 UTC'), $this->xml.'<!-- UNKNOWN-RATE -->');
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(['KSEF-1'], $this->storedNumbers());
        self::assertSame(KsefErrorCode::InvoiceUnreadable, $this->connection->getLastSyncError());
        self::assertStringContainsString('KSEF-UNKNOWN-RATE', (string) $this->connection->getLastSyncErrorMessage());
    }

    public function test_it_stops_at_an_invoice_that_does_not_match_its_hash_and_keeps_the_cursor_before_it(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-2', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);
        $this->ksef->corruptDownloadOf('KSEF-2');

        $this->runJob();

        self::assertSame(['KSEF-1'], $this->storedNumbers());
        self::assertSame(KsefErrorCode::RequestFailed, $this->connection->getLastSyncError());
        self::assertStringContainsString('KSEF-2', (string) $this->connection->getLastSyncErrorMessage());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->connection->getSyncCursor());
        self::assertNull($this->connection->getLastSyncSucceededAt());
        self::assertSame(['in-memory-1'], $this->ksef->endedSessions);
    }

    public function test_it_records_a_rate_limit_keeps_the_cursor_and_waits_for_retry_after(): void
    {
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);
        $this->ksef->addInvoice(self::NIP, 'KSEF-2', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), $this->xml);
        $this->ksef->failDownloadOf('KSEF-2', new KsefRateLimitedException(1800, 'Przekroczono limit'));

        $this->runJob();

        self::assertSame(['KSEF-1'], $this->storedNumbers());
        self::assertSame(KsefErrorCode::RateLimited, $this->connection->getLastSyncError());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->connection->getSyncCursor());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 21:30:00'), $this->connection->getSyncRetryNotBefore());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());

        $this->clock->modify('+20 minutes');
        $this->runJob();
        self::assertSame(['KSEF-1'], $this->storedNumbers(), 'No run before Retry-After.');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getLastSyncAt());

        $this->clock->modify('+10 minutes');
        $this->runJob();
        self::assertSame(['KSEF-1', 'KSEF-2'], $this->storedNumbers());
        self::assertNull($this->connection->getLastSyncError());
        self::assertNull($this->connection->getSyncRetryNotBefore());
    }

    public function test_it_records_ksef_being_unavailable_and_returns(): void
    {
        $this->ksef->failNextCallWith(KsefUnavailableException::afterRetries('auth challenge', 3, 'Connection refused'));

        $this->runJob();

        self::assertSame(KsefErrorCode::Unavailable, $this->connection->getLastSyncError());
        self::assertSame(KsefConnectionStatus::Active, $this->connection->getStatus());
    }

    public function test_it_marks_a_rejected_token_and_stops_running_until_a_new_one(): void
    {
        $this->ksef->acceptToken(self::NIP, 'another-token');
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame(KsefConnectionStatus::CredentialsRejected, $this->connection->getStatus());
        self::assertSame(KsefErrorCode::CredentialsRejected, $this->connection->getLastSyncError());
        self::assertSame([], $this->storedNumbers());

        $commandCount = \count($this->commandBus->commands);
        $this->clock->modify('+1 hour');
        $this->runJob();
        self::assertCount($commandCount, $this->commandBus->commands, 'Nothing runs with a rejected token.');
    }

    public function test_it_marks_a_token_that_no_longer_decrypts_as_unreadable(): void
    {
        $this->tokenUnreadable = true;

        $this->runJob();

        self::assertSame(KsefConnectionStatus::CredentialsRejected, $this->connection->getStatus());
        self::assertSame(KsefErrorCode::TokenUnreadable, $this->connection->getLastSyncError());
    }

    public function test_it_marks_a_missing_token_as_unreadable(): void
    {
        $this->token = null;

        $this->runJob();

        self::assertSame(KsefErrorCode::TokenUnreadable, $this->connection->getLastSyncError());
    }

    public function test_it_does_nothing_for_an_organization_no_longer_in_poland(): void
    {
        $this->organization->changeSettings($this->ownerId, 'Corner Pub', 'DE123456789', 'DE', 'EUR', 'Europe/Berlin');
        $this->ksef->addInvoice(self::NIP, 'KSEF-1', new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->xml);

        $this->runJob();

        self::assertSame([], $this->commandBus->commands);
        self::assertSame([], $this->ksef->downloaded);
        self::assertNull($this->connection->getLastSyncAt());
    }

    public function test_it_does_nothing_without_a_token(): void
    {
        $this->connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);

        $this->runJob();

        self::assertSame([], $this->commandBus->commands);
        self::assertNull($this->connection->getLastSyncAt());
    }

    public function test_it_refuses_a_job_for_another_organization_than_the_tenant(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler()(new ImportKsefInvoicesJob(Uuid::v7()->toRfc4122()));
    }

    public function test_it_stops_cleanly_after_its_time_budget_and_the_next_run_continues(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->ksef->addInvoice(self::NIP, 'KSEF-'.$i, new \DateTimeImmutable('2026-11-0'.$i.' 10:00:00 UTC'), $this->xml);
        }
        // Each stored invoice takes 20 minutes: the budget of 45 minutes ends after the third.
        $this->afterCommand = function (CommandInterface $command): void {
            if ($command instanceof ImportPurchaseInvoiceCommand) {
                $this->clock->sleep(20 * 60);
            }
        };

        $this->runJob();

        self::assertSame(['KSEF-1', 'KSEF-2', 'KSEF-3'], $this->storedNumbers());
        self::assertSame(3, $this->connection->getLastSyncImportedCount());
        self::assertNull($this->connection->getLastSyncError());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'), $this->connection->getSyncCursor());
        self::assertSame(['in-memory-1'], $this->ksef->endedSessions);

        $this->afterCommand = null;
        $this->runJob();
        self::assertSame(['KSEF-1', 'KSEF-2', 'KSEF-3', 'KSEF-4'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount());
    }

    public function test_it_continues_after_an_interruption_without_storing_an_invoice_twice(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->ksef->addInvoice(self::NIP, 'KSEF-'.$i, new \DateTimeImmutable('2026-11-0'.$i.' 10:00:00 UTC'), $this->xml);
        }
        // The worker dies (here: the database goes away) while storing the second invoice.
        $this->afterCommand = function (CommandInterface $command): void {
            if ($command instanceof ImportPurchaseInvoiceCommand && 'KSEF-2' === $command->externalId) {
                throw new \RuntimeException('Connection to the database lost.');
            }
        };

        try {
            $this->runJob();
            self::fail('The interruption was expected to escape: Messenger retries the job.');
        } catch (\RuntimeException $e) {
            self::assertSame('Connection to the database lost.', $e->getMessage());
        }
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00 UTC'), $this->connection->getSyncCursor());

        $this->afterCommand = null;
        $this->runJob();

        self::assertSame(['KSEF-1', 'KSEF-2', 'KSEF-3'], $this->storedNumbers());
        self::assertSame(1, $this->connection->getLastSyncImportedCount(), 'KSEF-2 was stored before the interruption.');
    }

    public function test_it_downloads_one_at_a_time_while_at_most_64_invoices_are_waiting(): void
    {
        $this->addInvoices(64);

        $this->runJob();

        self::assertCount(64, $this->storedNumbers());
        self::assertCount(64, $this->ksef->downloaded);
        self::assertSame([], $this->ksef->exports);
        self::assertSame(KsefImportMethod::Single, $this->connection->getLastSyncMethod());
        self::assertSame(0, $this->connection->getImportBacklogCount());
        self::assertFalse($this->connection->isImportBacklogMinimum());
    }

    public function test_it_counts_only_new_fa3_invoices_issued_from_the_start_date_as_waiting(): void
    {
        $this->addInvoices(64);
        for ($i = 0; $i < 10; ++$i) {
            $this->ksef->addInvoice(self::NIP, 'KSEF-FA2-'.$i, new \DateTimeImmutable('2026-10-20 08:00:00 UTC')->modify(\sprintf('+%d minutes', $i)), $this->xml, new KsefFormCode('FA (2)', '1-0E', 'FA'));
            $this->ksef->addInvoice(self::NIP, 'KSEF-SEPTEMBER-'.$i, new \DateTimeImmutable('2026-10-21 08:00:00 UTC')->modify(\sprintf('+%d minutes', $i)), $this->xml, issueDate: BusinessDate::fromString('2026-09-28'));
        }

        $this->runJob();

        self::assertSame([], $this->ksef->exports);
        self::assertCount(64, $this->storedNumbers());
        self::assertSame(10, $this->connection->getLastSyncSkippedCount());
    }

    public function test_it_imports_more_than_64_waiting_invoices_with_a_package_export(): void
    {
        $this->addInvoices(65);

        $this->runJob();

        self::assertCount(65, $this->storedNumbers());
        self::assertSame([], $this->ksef->downloaded, 'nothing downloaded one at a time');
        self::assertCount(1, $this->ksef->exports);
        $export = $this->ksef->exports['export-1'];
        self::assertEquals(new \DateTimeImmutable(self::FIRST_RUN_FROM), $export['from']);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $export['to']);
        self::assertSame(['export-1'], $this->ksef->openedPackages);
        self::assertSame(0, $this->ksef->openPackages, 'the package is closed');
        self::assertNull($this->connection->getPendingExport());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor());
        self::assertSame(65, $this->connection->getLastSyncImportedCount());
        self::assertSame(KsefImportMethod::Export, $this->connection->getLastSyncMethod());
        self::assertSame(0, $this->connection->getImportBacklogCount());
        self::assertNull($this->connection->getLastSyncError());
    }

    public function test_it_switches_to_the_export_from_the_cursor_once_more_than_64_turn_out_to_wait(): void
    {
        // Pages of 50: the first one is imported one at a time, the second shows 70 waiting in all.
        $this->ksef->truncateAfter = 50;
        $this->addInvoices(70);

        $this->runJob();

        self::assertCount(70, $this->storedNumbers());
        self::assertCount(50, $this->ksef->downloaded);
        self::assertCount(1, $this->ksef->exports);
        self::assertEquals(new \DateTimeImmutable('2026-10-10 08:49:00 UTC'), $this->ksef->exports['export-1']['from'], 'the cursor after the 50th invoice');
        self::assertSame(70, $this->connection->getLastSyncImportedCount());
        self::assertSame(KsefImportMethod::Export, $this->connection->getLastSyncMethod());
    }

    public function test_it_leaves_a_package_ksef_has_not_finished_to_the_next_run(): void
    {
        $this->ksef->exportReadyAfterChecks = 1;
        $this->addInvoices(80);

        $this->runJob();

        self::assertSame([], $this->storedNumbers());
        $pending = $this->connection->getPendingExport();
        self::assertNotNull($pending);
        self::assertSame('export-1', $pending->referenceNumber);
        self::assertSame($this->ksef->exports['export-1']['keyId'], $pending->keyId);
        self::assertNull($this->connection->getLastSyncError());
        self::assertNull($this->connection->getImportBacklogCount(), 'unknown until the package is ready');
        self::assertNotNull($this->connection->importEstimatedAt($this->clock->now()));

        $this->clock->modify('+1 hour');
        $this->runJob();

        self::assertCount(80, $this->storedNumbers());
        self::assertCount(1, $this->ksef->exports, 'the same export, not a new one');
        self::assertNull($this->connection->getPendingExport());
        self::assertSame(0, $this->connection->getImportBacklogCount());
        self::assertNull($this->connection->importEstimatedAt($this->clock->now()));
    }

    public function test_it_resumes_a_package_it_had_no_time_to_finish_without_storing_an_invoice_twice(): void
    {
        $this->addInvoices(100);
        // Each stored invoice takes a minute: the budget of 45 minutes ends in the middle of the package.
        $this->afterCommand = function (CommandInterface $command): void {
            if ($command instanceof ImportPurchaseInvoiceCommand) {
                $this->clock->sleep(60);
            }
        };

        $this->runJob();

        self::assertCount(45, $this->storedNumbers());
        self::assertNotNull($this->connection->getPendingExport());
        self::assertSame(55, $this->connection->getImportBacklogCount());
        self::assertTrue($this->connection->isImportBacklogMinimum());

        $this->afterCommand = null;
        $this->runJob();

        self::assertCount(100, $this->storedNumbers());
        self::assertSame(55, $this->connection->getLastSyncImportedCount());
        self::assertCount(1, $this->ksef->exports);
        self::assertSame(['export-1', 'export-1'], $this->ksef->openedPackages);
        self::assertNull($this->connection->getPendingExport());
    }

    public function test_it_passes_over_packaged_invoices_already_in_expenses(): void
    {
        $this->addInvoices(5, 'KSEF-EARLY', '2026-10-02 08:00:00 UTC');
        $this->runJob();
        self::assertSame([], $this->ksef->exports);
        // An earlier start reads the same days again, now with more than 64 waiting.
        $this->chooseStartDate('2026-09-01');
        $this->addInvoices(65, 'KSEF-SEPTEMBER', '2026-09-02 08:00:00 UTC');

        $this->runJob();

        self::assertCount(70, $this->storedNumbers());
        self::assertSame(65, $this->connection->getLastSyncImportedCount());
        self::assertCount(1, $this->ksef->exports);
    }

    public function test_it_continues_with_a_new_export_after_a_truncated_package(): void
    {
        $this->ksef->packageLimit = 40;
        $this->addInvoices(100);

        $this->runJob();

        self::assertCount(100, $this->storedNumbers());
        self::assertCount(3, $this->ksef->exports);
        self::assertEquals(new \DateTimeImmutable('2026-10-10 08:39:00 UTC'), $this->ksef->exports['export-2']['from'], 'the last invoice of the first package');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->connection->getSyncCursor());
        self::assertSame(0, $this->connection->getImportBacklogCount());
    }

    public function test_it_requests_a_new_export_when_the_pending_one_expired(): void
    {
        $this->ksef->exportReadyAfterChecks = 1;
        $this->addInvoices(70);
        $this->runJob();
        $this->ksef->answerExportWith('export-1', KsefInvoiceExportStatus::expired());
        $this->ksef->exportReadyAfterChecks = 0;

        $this->runJob();

        self::assertCount(70, $this->storedNumbers());
        self::assertSame(['export-2'], $this->ksef->openedPackages);
        self::assertNull($this->connection->getPendingExport());
    }

    public function test_it_drops_a_failed_export_and_records_why(): void
    {
        $this->ksef->exportReadyAfterChecks = 1;
        $this->addInvoices(70);
        $this->runJob();
        $this->ksef->answerExportWith('export-1', KsefInvoiceExportStatus::failed('550 Operacja została anulowana przez system', true));

        $this->runJob();

        self::assertSame([], $this->storedNumbers());
        self::assertSame(KsefErrorCode::Unavailable, $this->connection->getLastSyncError());
        self::assertStringContainsString('export-1 failed: 550', (string) $this->connection->getLastSyncErrorMessage());
        self::assertNull($this->connection->getPendingExport(), 'the next run requests a new one');
    }

    public function test_it_gives_up_a_pending_export_whose_range_no_longer_holds_the_cursor(): void
    {
        $this->addInvoices(70);
        $this->connection->exportStarted(new KsefInvoiceExport('export-stale', Uuid::v7()->toRfc4122(), new \DateTimeImmutable('2026-11-01 00:00 UTC'), new \DateTimeImmutable('2026-11-02 00:00 UTC'), new \DateTimeImmutable('2026-11-02 00:00 UTC')));

        $this->runJob();

        self::assertCount(70, $this->storedNumbers());
        self::assertSame(['export-1'], $this->ksef->openedPackages);
        self::assertNull($this->connection->getPendingExport());
    }

    public function test_it_stops_at_a_packaged_invoice_that_does_not_match_its_hash_and_drops_the_export(): void
    {
        $this->addInvoices(70);
        $this->ksef->corruptDownloadOf('KSEF-011');

        $this->runJob();

        self::assertCount(11, $this->storedNumbers());
        self::assertSame(KsefErrorCode::RequestFailed, $this->connection->getLastSyncError());
        self::assertEquals(new \DateTimeImmutable('2026-10-10 08:10:00 UTC'), $this->connection->getSyncCursor(), 'before the corrupted invoice');
        self::assertNull($this->connection->getPendingExport());
        self::assertSame(0, $this->ksef->openPackages);
    }

    public function test_it_records_at_least_the_invoices_it_counted_when_it_stops_early(): void
    {
        $this->addInvoices(30);
        $this->ksef->failDownloadOf('KSEF-010', new KsefRateLimitedException(600, 'Too many requests.'));

        $this->runJob();

        self::assertSame(20, $this->connection->getImportBacklogCount());
        self::assertTrue($this->connection->isImportBacklogMinimum());
        self::assertSame(KsefImportMethod::Single, $this->connection->getLastSyncMethod());
    }

    /** `$count` FA(3) invoices stored a minute apart from `$from`, numbered from 000. */
    private function addInvoices(int $count, string $prefix = 'KSEF', string $from = '2026-10-10 08:00:00 UTC'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->ksef->addInvoice(self::NIP, \sprintf('%s-%03d', $prefix, $i), new \DateTimeImmutable($from)->modify(\sprintf('+%d minutes', $i)), $this->xml);
        }
    }

    private function chooseStartDate(string $date): void
    {
        $this->connection->changeSyncStartDate(BusinessDate::fromString($date), $this->ownerId, MembershipRole::Owner, new \DateTimeZone('Europe/Warsaw'), $this->clock->now());
    }

    private function runJob(): void
    {
        $this->handler()(new ImportKsefInvoicesJob($this->organization->getId()->toRfc4122()));
    }

    private function handler(): ImportKsefInvoicesJobHandler
    {
        $storedIds = new ListStoredExternalIdsHandler($this->invoices, $this->tenantContext);
        $queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof RevealOrganizationSecretQuery => $this->tokenUnreadable ? throw SecretUnreadableException::ofSecret($query->secretId, SecretUnreadableException::unknownKey('old-1')) : $this->token,
            $query instanceof ListStoredExternalIdsQuery => $storedIds($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });

        return new ImportKsefInvoicesJobHandler($this->connections, $this->ksef, $this->parser, $this->tenantContext, $queryBus, $this->commandBus, $this->clock, $this->logger);
    }

    /** @return list<string> the KSeF numbers stored in Expenses, sorted */
    private function storedNumbers(): array
    {
        $numbers = array_map(static fn (PurchaseInvoice $invoice): string => (string) $invoice->getExternalId(), $this->invoices->all());
        sort($numbers);

        return $numbers;
    }
}
