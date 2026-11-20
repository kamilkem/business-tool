<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Model\KsefAccess;
use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Model\KsefConnectionStatus;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Organization\Domain\Model\MembershipRole;
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
        $connection->recordSyncRun(self::now(), self::now('2026-11-13 08:00:00'), 3, 1);

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

        $connection->recordSyncRun(self::now('2026-11-13 02:00:00'), self::now('2026-11-13 01:00:00'), 5, 2);
        $connection->recordSyncRun(self::now('2026-11-13 03:00:00'), self::now('2026-11-13 01:00:00'), 0, 0, KsefErrorCode::RateLimited, 'retry after 60 s');

        self::assertSame(KsefConnectionStatus::Active, $connection->getStatus());
        self::assertEquals(self::now('2026-11-13 03:00:00'), $connection->getLastSyncAt());
        self::assertEquals(self::now('2026-11-13 02:00:00'), $connection->getLastSyncSucceededAt());
        self::assertEquals(self::now('2026-11-13 02:00:00'), $connection->getVerifiedAt());
        self::assertSame(KsefErrorCode::RateLimited, $connection->getLastSyncError());
        self::assertSame('retry after 60 s', $connection->getLastSyncErrorMessage());
        self::assertSame(0, $connection->getLastSyncSkippedCount());

        $connection->recordSyncRun(self::now('2026-11-13 04:00:00'), self::now('2026-11-13 01:00:00'), 0, 0, KsefErrorCode::CredentialsRejected, 'revoked');

        self::assertSame(KsefConnectionStatus::CredentialsRejected, $connection->getStatus());
    }

    public function test_it_lets_owners_and_managers_use_the_connection_but_not_employees(): void
    {
        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Owner);
        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Manager);

        $this->expectException(KsefConnectionForbiddenException::class);

        KsefAccess::assertCanUse(Uuid::v7(), MembershipRole::Employee);
    }
}
