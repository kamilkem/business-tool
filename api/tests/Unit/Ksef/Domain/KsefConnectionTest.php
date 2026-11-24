<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Exception\InvalidSyncStartDateException;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefImportTooSoonException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefTokenNotSetException;
use App\Ksef\Domain\Exception\KsefTokenRejectedException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Model\KsefConnectionStatus;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(KsefConnection::class)]
#[CoversClass(KsefAccess::class)]
#[CoversClass(KsefErrorCode::class)]
final class KsefConnectionTest extends TestCase
{
    private const string NIP = '1111111111';

    private static function now(string $time = '2026-11-13 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    private static function connection(): KsefConnection
    {
        return KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', self::NIP, Uuid::v7(), MembershipRole::Owner, self::now());
    }

    public function test_it_starts_active_with_the_token_not_verified(): void
    {
        $secretId = Uuid::v7();

        $connection = KsefConnection::connect(Uuid::v7(), $secretId, 'pl', 'PL 111-111-11-11', Uuid::v7(), MembershipRole::Owner, self::now());

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertSame($secretId, $connection->getSecretId());
        self::assertNull($connection->getVerifiedAt());
        self::assertNull($connection->getSyncCursor());
        self::assertSame(0, $connection->getLastSyncImportedCount());
        self::assertEquals(self::now(), $connection->getCreatedAt());
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function nonOwners(): iterable
    {
        yield 'manager' => [MembershipRole::Manager];
        yield 'employee' => [MembershipRole::Employee];
    }

    #[DataProvider('nonOwners')]
    public function test_it_lets_only_owners_connect_save_or_remove_the_token(MembershipRole $role): void
    {
        $connection = self::connection();
        $attempts = [
            static fn () => KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', self::NIP, Uuid::v7(), $role, self::now()),
            static fn () => $connection->tokenSaved(Uuid::v7(), 'PL', self::NIP, Uuid::v7(), $role),
            static fn () => $connection->tokenRemoved(Uuid::v7(), $role),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Exception expected.');
            } catch (KsefConnectionForbiddenException) {
            }
        }
        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function unavailable(): iterable
    {
        yield 'another country' => ['DE', self::NIP, 'only available for organizations in Poland'];
        yield 'no tax id' => ['PL', null, 'valid NIP'];
        yield 'invalid NIP' => ['PL', '1111111112', 'valid NIP'];
    }

    #[DataProvider('unavailable')]
    public function test_it_exists_only_in_poland_with_a_valid_nip(string $country, ?string $taxId, string $message): void
    {
        $this->expectException(KsefNotAvailableException::class);
        $this->expectExceptionMessage($message);

        KsefConnection::connect(Uuid::v7(), Uuid::v7(), $country, $taxId, Uuid::v7(), MembershipRole::Owner, self::now());
    }

    #[DataProvider('unavailable')]
    public function test_it_refuses_a_new_token_outside_poland_or_without_a_valid_nip(string $country, ?string $taxId, string $message): void
    {
        $connection = self::connection();
        $connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);

        $this->expectException(KsefNotAvailableException::class);

        $connection->tokenSaved(Uuid::v7(), $country, $taxId, Uuid::v7(), MembershipRole::Owner);
    }

    public function test_it_is_verified_by_a_successful_check(): void
    {
        $connection = self::connection();
        $connection->recordCheck(self::now('2026-11-13 10:00:00'), KsefErrorCode::CredentialsRejected, 'refused');

        $connection->recordCheck(self::now('2026-11-13 11:00:00'));

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertEquals(self::now('2026-11-13 11:00:00'), $connection->getVerifiedAt());
        self::assertEquals(self::now('2026-11-13 11:00:00'), $connection->getLastCheckedAt());
        self::assertNull($connection->getLastCheckError());
        self::assertNull($connection->getLastCheckErrorMessage());
    }

    /** @return iterable<string, array{KsefErrorCode, KsefConnectionStatus}> */
    public static function failedChecks(): iterable
    {
        yield 'credentials rejected' => [KsefErrorCode::CredentialsRejected, KsefConnectionStatus::CredentialsRejected];
        yield 'token unreadable' => [KsefErrorCode::TokenUnreadable, KsefConnectionStatus::CredentialsRejected];
        yield 'rate limited' => [KsefErrorCode::RateLimited, KsefConnectionStatus::Active];
        yield 'unavailable' => [KsefErrorCode::Unavailable, KsefConnectionStatus::Active];
        yield 'request failed' => [KsefErrorCode::RequestFailed, KsefConnectionStatus::Active];
    }

    #[DataProvider('failedChecks')]
    public function test_it_records_a_failed_check_and_rejects_only_bad_credentials(KsefErrorCode $error, KsefConnectionStatus $status): void
    {
        $connection = self::connection();
        $connection->recordCheck(self::now('2026-11-13 09:00:00'));

        $connection->recordCheck(self::now(), $error, str_repeat('x', 1200));

        self::assertSame($status, $connection->getStatus());
        self::assertSame($error, $connection->getLastCheckError());
        self::assertSame(str_repeat('x', KsefConnection::ERROR_MESSAGE_MAX_LENGTH), $connection->getLastCheckErrorMessage());
        self::assertEquals(self::now('2026-11-13 09:00:00'), $connection->getVerifiedAt());
    }

    public function test_it_forgets_checks_when_a_new_token_is_saved(): void
    {
        $connection = self::connection();
        $connection->recordCheck(self::now(), KsefErrorCode::CredentialsRejected, 'refused');
        $secretId = Uuid::v7();

        $connection->tokenSaved($secretId, 'PL', self::NIP, Uuid::v7(), MembershipRole::Owner);

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertSame($secretId, $connection->getSecretId());
        self::assertNull($connection->getVerifiedAt());
        self::assertNull($connection->getLastCheckedAt());
        self::assertNull($connection->getLastCheckError());
    }

    public function test_it_is_disabled_without_a_token_and_keeps_the_sync_state(): void
    {
        $connection = self::connection();
        $connection->advanceSyncCursor(self::now('2026-11-13 08:00:00'));
        $connection->recordSyncRun(self::now(), 3, 1);

        $connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);

        self::assertSame(KsefConnectionStatus::Disabled, $connection->getStatus());
        self::assertNull($connection->getSecretId());
        self::assertNull($connection->getVerifiedAt());
        self::assertEquals(self::now('2026-11-13 08:00:00'), $connection->getSyncCursor());
        self::assertSame(3, $connection->getLastSyncImportedCount());
    }

    public function test_it_records_sync_runs(): void
    {
        $connection = self::connection();

        $connection->recordSyncRun(self::now('2026-11-13 02:00:00'), 5, 2);
        $connection->recordSyncRun(self::now('2026-11-13 03:00:00'), 0, 0, KsefErrorCode::RateLimited, 'retry after 60 s', self::now('2026-11-13 03:01:00'));

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertEquals(self::now('2026-11-13 03:00:00'), $connection->getLastSyncAt());
        self::assertEquals(self::now('2026-11-13 02:00:00'), $connection->getLastSyncSucceededAt());
        self::assertEquals(self::now('2026-11-13 02:00:00'), $connection->getVerifiedAt());
        self::assertSame(KsefErrorCode::RateLimited, $connection->getLastSyncError());
        self::assertSame('retry after 60 s', $connection->getLastSyncErrorMessage());
        self::assertSame(0, $connection->getLastSyncSkippedCount());
        self::assertEquals(self::now('2026-11-13 03:01:00'), $connection->getSyncRetryNotBefore());

        $connection->recordSyncRun(self::now('2026-11-13 04:00:00'), 0, 0, KsefErrorCode::CredentialsRejected, 'revoked');

        self::assertSame(KsefConnectionStatus::CredentialsRejected, $connection->getStatus());
        self::assertNull($connection->getSyncRetryNotBefore());
    }

    public function test_it_counts_a_run_that_skipped_unreadable_invoices_as_completed(): void
    {
        $connection = self::connection();

        $connection->recordSyncRun(self::now('2026-11-13 02:00:00'), 4, 1, KsefErrorCode::InvoiceUnreadable, 'Skipped 1 invoice(s)');

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertEquals(self::now('2026-11-13 02:00:00'), $connection->getLastSyncSucceededAt());
        self::assertSame(KsefErrorCode::InvoiceUnreadable, $connection->getLastSyncError());
        self::assertSame('Skipped 1 invoice(s)', $connection->getLastSyncErrorMessage());
    }

    public function test_it_moves_the_cursor_only_forward_in_utc(): void
    {
        $connection = self::connection();

        $connection->advanceSyncCursor(new \DateTimeImmutable('2026-11-13 08:00:00.999+01:00'));
        $connection->advanceSyncCursor(self::now('2026-11-13 06:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-11-13 07:00:00 UTC'), $connection->getSyncCursor(), 'Rounded down to the second.');
        self::assertSame('UTC', $connection->getSyncCursor()?->getTimezone()->getName());
    }

    public function test_it_starts_the_first_sync_on_the_first_day_of_the_previous_month_in_the_organizations_time_zone(): void
    {
        // Created on 1 November 00:30 in Warsaw, which is still 31 October in UTC.
        $connection = KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', self::NIP, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-31 23:30:00 UTC'));

        self::assertEquals(new \DateTimeImmutable('2026-09-30 22:00:00 UTC'), $connection->syncFrom(new \DateTimeZone('Europe/Warsaw')));
        self::assertEquals(new \DateTimeImmutable('2026-09-01 00:00:00 UTC'), $connection->syncFrom(new \DateTimeZone('UTC')));

        $connection->advanceSyncCursor(self::now('2026-11-13 06:00:00'));

        self::assertEquals(self::now('2026-11-13 06:00:00'), $connection->syncFrom(new \DateTimeZone('Europe/Warsaw')));
    }

    public function test_it_syncs_only_while_active_with_a_token_and_after_retry_after(): void
    {
        $connection = self::connection();
        self::assertTrue($connection->canSync(self::now()));

        $connection->recordSyncRun(self::now(), 0, 0, KsefErrorCode::RateLimited, '429', self::now('2026-11-13 11:00:00'));
        self::assertFalse($connection->canSync(self::now('2026-11-13 10:59:59')));
        self::assertTrue($connection->canSync(self::now('2026-11-13 11:00:00')));

        $connection->recordSyncRun(self::now(), 0, 0, KsefErrorCode::CredentialsRejected, 'revoked');
        self::assertFalse($connection->canSync(self::now('2026-11-14 10:00:00')));

        $connection->tokenSaved(Uuid::v7(), 'PL', self::NIP, Uuid::v7(), MembershipRole::Owner);
        self::assertTrue($connection->canSync(self::now()));

        $connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);
        self::assertFalse($connection->canSync(self::now()));
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_accepts_import_now_once_every_15_minutes(MembershipRole $role): void
    {
        $connection = self::connection();

        $connection->requestImport('PL', self::NIP, Uuid::v7(), $role, self::now());
        self::assertEquals(self::now(), $connection->getLastImportRequestedAt());

        try {
            $connection->requestImport('PL', self::NIP, Uuid::v7(), $role, self::now('2026-11-13 10:14:59'));
            self::fail('A second request within 15 minutes was accepted.');
        } catch (KsefImportTooSoonException $e) {
            self::assertEquals(self::now('2026-11-13 10:15:00'), $e->retryAt);
        }

        $connection->requestImport('PL', self::NIP, Uuid::v7(), $role, self::now('2026-11-13 10:15:00'));
        self::assertEquals(self::now('2026-11-13 10:15:00'), $connection->getLastImportRequestedAt());
    }

    public function test_it_refuses_import_now_while_ksefs_retry_after_runs(): void
    {
        $connection = self::connection();
        $connection->recordSyncRun(self::now(), 0, 0, KsefErrorCode::RateLimited, '429', self::now('2026-11-13 12:00:00'));

        $this->expectException(KsefImportTooSoonException::class);

        $connection->requestImport('PL', self::NIP, Uuid::v7(), MembershipRole::Owner, self::now('2026-11-13 11:00:00'));
    }

    public function test_it_refuses_import_now_to_employees(): void
    {
        $this->expectException(KsefConnectionForbiddenException::class);

        self::connection()->requestImport('PL', self::NIP, Uuid::v7(), MembershipRole::Employee, self::now());
    }

    public function test_it_refuses_import_now_outside_poland(): void
    {
        $this->expectException(KsefNotAvailableException::class);

        self::connection()->requestImport('DE', 'DE123456789', Uuid::v7(), MembershipRole::Owner, self::now());
    }

    public function test_it_refuses_import_now_without_a_token(): void
    {
        $connection = self::connection();
        $connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);

        $this->expectException(KsefTokenNotSetException::class);

        $connection->requestImport('PL', self::NIP, Uuid::v7(), MembershipRole::Owner, self::now());
    }

    public function test_it_refuses_import_now_with_a_rejected_token(): void
    {
        $connection = self::connection();
        $connection->recordCheck(self::now(), KsefErrorCode::CredentialsRejected, 'revoked');

        $this->expectException(KsefTokenRejectedException::class);

        $connection->requestImport('PL', self::NIP, Uuid::v7(), MembershipRole::Owner, self::now());
    }

    public function test_it_lets_owners_and_managers_use_the_connection_but_not_employees(): void
    {
        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Owner);
        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Manager);

        $this->expectException(KsefConnectionForbiddenException::class);

        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Employee);
    }

    private static function warsaw(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Warsaw');
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function ownersAndManagers(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('ownersAndManagers')]
    public function test_it_starts_the_import_at_the_chosen_date_in_the_organizations_time_zone(MembershipRole $role): void
    {
        $connection = self::connection();

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-03-01'), Uuid::v7(), $role, self::warsaw(), self::now());

        self::assertEquals(BusinessDate::fromString('2026-03-01'), $connection->getSyncStartDate());
        self::assertEquals(BusinessDate::fromString('2026-03-01'), $connection->effectiveSyncStartDate(self::warsaw()));
        self::assertEquals(new \DateTimeImmutable('2026-02-28 23:00:00 UTC'), $connection->syncFrom(self::warsaw()));
        self::assertEquals(BusinessDate::fromString('2026-10-01'), $connection->defaultSyncStartDate(self::warsaw()));
    }

    public function test_it_goes_back_to_the_default_start_when_the_date_is_cleared(): void
    {
        $connection = self::connection();
        $connection->changeSyncStartDate(BusinessDate::fromString('2026-11-01'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());

        $connection->changeSyncStartDate(null, Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());

        self::assertNull($connection->getSyncStartDate());
        self::assertEquals(BusinessDate::fromString('2026-10-01'), $connection->effectiveSyncStartDate(self::warsaw()));
    }

    public function test_it_reads_again_from_an_earlier_start_date(): void
    {
        $connection = self::connection();
        $connection->advanceSyncCursor(self::now('2026-11-12 18:00:00'));

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-02-01'), Uuid::v7(), MembershipRole::Manager, self::warsaw(), self::now());

        self::assertNull($connection->getSyncCursor());
        self::assertEquals(new \DateTimeImmutable('2026-01-31 23:00:00 UTC'), $connection->syncFrom(self::warsaw()));
    }

    public function test_it_keeps_the_cursor_when_the_start_moves_later_but_not_past_it(): void
    {
        $connection = self::connection();
        $connection->advanceSyncCursor(self::now('2026-11-12 18:00:00'));

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-11-01'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());

        self::assertEquals(self::now('2026-11-12 18:00:00'), $connection->getSyncCursor());
        self::assertEquals(self::now('2026-11-12 18:00:00'), $connection->syncFrom(self::warsaw()));
    }

    public function test_it_starts_at_a_later_start_date_past_the_cursor(): void
    {
        $connection = self::connection();
        $connection->advanceSyncCursor(self::now('2026-10-20 18:00:00'));

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-11-10'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());

        self::assertNull($connection->getSyncCursor(), 'The days between the cursor and the new start are not imported.');
        self::assertEquals(new \DateTimeImmutable('2026-11-09 23:00:00 UTC'), $connection->syncFrom(self::warsaw()));
    }

    public function test_it_accepts_today_in_the_organizations_time_zone_as_the_start(): void
    {
        $connection = self::connection();

        // 23:30 UTC on 13 November is already 14 November in Warsaw.
        $connection->changeSyncStartDate(BusinessDate::fromString('2026-11-14'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now('2026-11-13 23:30:00'));

        self::assertEquals(BusinessDate::fromString('2026-11-14'), $connection->getSyncStartDate());
    }

    public function test_it_refuses_a_start_date_after_today(): void
    {
        $connection = self::connection();

        $this->expectException(InvalidSyncStartDateException::class);
        $this->expectExceptionMessage('after today (2026-11-13)');

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-11-14'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());
    }

    public function test_it_refuses_a_start_date_before_ksef_api_2(): void
    {
        $connection = self::connection();

        try {
            $connection->changeSyncStartDate(BusinessDate::fromString('2026-01-31'), Uuid::v7(), MembershipRole::Owner, self::warsaw(), self::now());
            self::fail('A start before 2026-02-01 was accepted.');
        } catch (InvalidSyncStartDateException $e) {
            self::assertSame('ksef.invalid_sync_start_date.before_ksef', $e->errorCode());
            self::assertSame(['startDate' => '2026-01-31', 'earliest' => '2026-02-01'], $e->errorParameters());
        }
        self::assertNull($connection->getSyncStartDate());
    }

    public function test_it_refuses_employees_to_choose_the_start_date(): void
    {
        $connection = self::connection();

        $this->expectException(KsefConnectionForbiddenException::class);

        $connection->changeSyncStartDate(BusinessDate::fromString('2026-03-01'), Uuid::v7(), MembershipRole::Employee, self::warsaw(), self::now());
    }
}
