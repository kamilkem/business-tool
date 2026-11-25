<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Ksef\Application\Command\ChangeKsefSyncStartDate\ChangeKsefSyncStartDateCommand;
use App\Ksef\Application\Command\ChangeKsefSyncStartDate\ChangeKsefSyncStartDateHandler;
use App\Ksef\Application\Command\RemoveKsefToken\RemoveKsefTokenCommand;
use App\Ksef\Application\Command\RemoveKsefToken\RemoveKsefTokenHandler;
use App\Ksef\Application\Command\RequestKsefImport\RequestKsefImportCommand;
use App\Ksef\Application\Command\RequestKsefImport\RequestKsefImportHandler;
use App\Ksef\Application\Command\SaveKsefToken\SaveKsefTokenCommand;
use App\Ksef\Application\Command\SaveKsefToken\SaveKsefTokenHandler;
use App\Ksef\Application\Command\TestKsefConnection\TestKsefConnectionCommand;
use App\Ksef\Application\Command\TestKsefConnection\TestKsefConnectionHandler;
use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\KsefActor;
use App\Ksef\Application\Query\GetKsefConnection\GetKsefConnectionHandler;
use App\Ksef\Application\Query\GetKsefConnection\GetKsefConnectionQuery;
use App\Ksef\Application\Query\GetKsefConnection\KsefConnectionView;
use App\Ksef\Application\Query\GetKsefTokenTarget\GetKsefTokenTargetHandler;
use App\Ksef\Application\Query\GetKsefTokenTarget\GetKsefTokenTargetQuery;
use App\Ksef\Application\Query\GetKsefTokenTarget\KsefTokenTargetView;
use App\Ksef\Domain\Exception\InvalidSyncStartDateException;
use App\Ksef\Domain\Exception\KsefConnectionForbiddenException;
use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\KsefImportTooSoonException;
use App\Ksef\Domain\Exception\KsefNotAvailableException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefTokenNotSetException;
use App\Ksef\Domain\Exception\KsefTokenRejectedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Model\KsefConnectionStatus;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Secret\Application\Command\RemoveOrganizationSecret\RemoveOrganizationSecretCommand;
use App\Secret\Application\Command\RemoveOrganizationSecret\RemoveOrganizationSecretHandler;
use App\Secret\Application\Command\SetOrganizationSecret\SetOrganizationSecretCommand;
use App\Secret\Application\Command\SetOrganizationSecret\SetOrganizationSecretHandler;
use App\Secret\Application\Query\GetOrganizationSecret\GetOrganizationSecretHandler;
use App\Secret\Application\Query\GetOrganizationSecret\GetOrganizationSecretQuery;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretHandler;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Infrastructure\Sodium\SecretKeyring;
use App\Secret\Infrastructure\Sodium\SodiumSecretCipher;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\Ksef\InMemoryKsefClient;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Secret\Application\InMemoryOrganizationSecretRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * A bar in Poland with a valid NIP, an owner, a manager and an employee. The Organization and Secret
 * modules answer through their real queries over in-memory repositories (the real sodium cipher with
 * a test key); KSeF is InMemoryKsefClient. The token is saved as the API does it: the secret, then the
 * connection.
 */
#[CoversClass(SaveKsefTokenHandler::class)]
#[CoversClass(RemoveKsefTokenHandler::class)]
#[CoversClass(TestKsefConnectionHandler::class)]
#[CoversClass(GetKsefConnectionHandler::class)]
#[CoversClass(RequestKsefImportHandler::class)]
#[CoversClass(ChangeKsefSyncStartDateHandler::class)]
#[CoversClass(GetKsefTokenTargetHandler::class)]
#[CoversClass(KsefConnectionView::class)]
#[CoversClass(KsefActor::class)]
final class KsefConnectionHandlersTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string NIP = '1111111111';
    private const string TOKEN = 'fake-ksef-token|nip-1111111111|not-a-real-token';

    private MockClock $clock;
    private InMemoryKsefConnectionRepository $connections;
    private InMemoryOrganizationSecretRepository $secrets;
    private InMemoryKsefClient $ksef;
    private SodiumSecretCipher $cipher;
    private StubQueryBus $queryBus;
    private RecordingJobBus $jobBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->connections = new InMemoryKsefConnectionRepository();
        $this->secrets = new InMemoryOrganizationSecretRepository();
        $this->ksef = new InMemoryKsefClient();
        $this->jobBus = new RecordingJobBus();
        $this->ksef->acceptToken(self::NIP, self::TOKEN);
        $this->cipher = new SodiumSecretCipher('k1:'.base64_encode(str_repeat('a', SecretKeyring::keyLength())));
        $organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = new Organization(Uuid::v7(), 'Corner Pub', $this->owner, $joined, 'PL 111-111-11-11');
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $logger = new RecordingLogger();
        $this->queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof GetOrganizationSecretQuery => (new GetOrganizationSecretHandler($this->secrets))($query),
            $query instanceof RevealOrganizationSecretQuery => (new RevealOrganizationSecretHandler($this->secrets, $this->cipher, $logger))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    public function test_it_connects_the_organization_when_an_owner_saves_the_first_token(): void
    {
        $this->saveToken();

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('active', $view->status);
        self::assertTrue($view->tokenSet);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $view->tokenSetAt);
        self::assertSame(self::NIP, $view->nip);
        self::assertNull($view->verifiedAt);
        self::assertNull($view->lastCheckedAt);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $view->createdAt);
        self::assertStringNotContainsString(self::TOKEN, print_r($view, true));
    }

    public function test_it_reuses_the_connection_and_secret_for_a_new_token(): void
    {
        $this->saveToken();
        $first = $this->target($this->owner, true);
        $this->clock->modify('+1 day');

        $this->saveToken('another-fake-token');

        self::assertEquals($first, $this->target($this->owner, true));
        self::assertEquals(new \DateTimeImmutable('2026-11-14 10:00:00'), $this->view($this->owner)?->tokenSetAt);
    }

    public function test_it_gives_owners_the_ids_to_save_or_remove_the_token_with(): void
    {
        self::assertEquals(new KsefTokenTargetView(null, null), $this->target($this->owner, true));

        $this->saveToken();

        $connection = $this->connections->findCurrent() ?? self::fail('Connection expected.');
        self::assertEquals(
            new KsefTokenTargetView($connection->getId()->toRfc4122(), $connection->getSecretId()?->toRfc4122()),
            $this->target($this->owner, false),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonOwners(): iterable
    {
        yield 'manager' => ['manager'];
        yield 'employee' => ['employee'];
    }

    #[DataProvider('nonOwners')]
    public function test_it_lets_only_owners_save_or_remove_the_token(string $actor): void
    {
        $this->saveToken();
        $user = $this->user($actor);
        $attempts = [
            fn () => $this->target($user, true),
            fn () => $this->target($user, false),
            fn () => $this->saveConnection($user, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()),
            fn () => $this->removeConnection($user),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Exception expected.');
            } catch (KsefConnectionForbiddenException) {
            }
        }
        self::assertTrue($this->view($this->owner)?->tokenSet);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function unavailable(): iterable
    {
        yield 'another country' => ['1111111111', 'DE'];
        yield 'no tax id' => [null, 'PL'];
        yield 'invalid NIP' => ['1111111112', 'PL'];
    }

    #[DataProvider('unavailable')]
    public function test_it_refuses_a_token_where_ksef_is_not_available(?string $taxId, string $country): void
    {
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', $taxId, $country, 'PLN', 'Europe/Warsaw');

        foreach ([fn () => $this->target($this->owner, true), fn () => $this->saveConnection($this->owner, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122())] as $attempt) {
            try {
                $attempt();
                self::fail('Exception expected.');
            } catch (KsefNotAvailableException) {
            }
        }
        self::assertNull($this->connections->findCurrent());
    }

    public function test_it_lets_an_owner_remove_the_token_wherever_the_organization_is(): void
    {
        $this->saveToken();
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', null, 'DE', 'EUR', 'Europe/Berlin');

        self::assertNotNull($this->target($this->owner, false)->secretId);
        $this->removeToken();

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('disabled', $view->status);
        self::assertFalse($view->tokenSet);
        self::assertNull($view->tokenSetAt);
        self::assertNull($view->nip);
        self::assertSame([], $this->secrets->findNotUnderKey('none'));
    }

    public function test_it_reports_removing_without_a_connection_as_not_found(): void
    {
        $this->expectException(KsefConnectionNotFoundException::class);

        $this->removeConnection($this->owner);
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_verifies_a_token_ksef_accepts_and_ends_the_session(string $actor): void
    {
        $this->saveToken();
        $this->clock->modify('+5 minutes');

        $this->test($this->user($actor));

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('active', $view->status);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 10:05:00'), $view->verifiedAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 10:05:00'), $view->lastCheckedAt);
        self::assertNull($view->lastCheckError);
        self::assertSame(['in-memory-1'], $this->ksef->endedSessions);
        self::assertSame([], $this->ksef->downloaded);
    }

    public function test_it_rejects_a_token_ksef_refuses(): void
    {
        $this->saveToken('a-token-ksef-does-not-know');

        $this->test($this->owner);

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('credentials_rejected', $view->status);
        self::assertSame('credentials_rejected', $view->lastCheckError);
        self::assertStringContainsString('450', (string) $view->lastCheckErrorMessage);
        self::assertNull($view->verifiedAt);
    }

    /** @return iterable<string, array{KsefException, string}> */
    public static function ksefFailures(): iterable
    {
        yield 'rate limited' => [new KsefRateLimitedException(60, 'Too many requests'), 'rate_limited'];
        yield 'unavailable' => [KsefUnavailableException::afterRetries('challenge', 3, 'timeout'), 'unavailable'];
        yield 'request failed' => [KsefRequestFailedException::malformedResponse('challenge', 'not JSON'), 'request_failed'];
    }

    #[DataProvider('ksefFailures')]
    public function test_it_records_other_failures_without_rejecting_the_token(KsefException $failure, string $error): void
    {
        $this->saveToken();
        $this->test($this->owner);
        $this->clock->modify('+1 hour');
        $this->ksef->failNextCallWith($failure);

        $this->test($this->owner);

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('active', $view->status);
        self::assertSame($error, $view->lastCheckError);
        self::assertSame($failure->getMessage(), $view->lastCheckErrorMessage);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $view->verifiedAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 11:00:00'), $view->lastCheckedAt);
    }

    public function test_it_rejects_a_token_that_no_longer_decrypts(): void
    {
        $this->saveToken();
        $this->cipher = new SodiumSecretCipher('k2:'.base64_encode(str_repeat('b', SecretKeyring::keyLength())));

        $this->test($this->owner);

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('credentials_rejected', $view->status);
        self::assertSame('token_unreadable', $view->lastCheckError);
        self::assertSame([], $this->ksef->endedSessions);
    }

    public function test_it_rejects_a_token_whose_secret_is_gone(): void
    {
        $this->saveToken();
        $secretId = $this->connections->findCurrent()?->getSecretId() ?? self::fail('Secret expected.');
        $this->secrets->remove($this->secrets->findById($secretId) ?? self::fail('Secret expected.'));

        $this->test($this->owner);

        self::assertSame('token_unreadable', $this->view($this->owner)?->lastCheckError);
    }

    public function test_it_refuses_to_check_without_a_connection_or_a_token(): void
    {
        try {
            $this->test($this->owner);
            self::fail('Exception expected.');
        } catch (KsefConnectionNotFoundException) {
        }

        $this->saveToken();
        $this->removeToken();

        $this->expectException(KsefTokenNotSetException::class);

        $this->test($this->owner);
    }

    public function test_it_refuses_to_check_once_the_organization_left_poland(): void
    {
        $this->saveToken();
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', '1111111111', 'DE', 'EUR', 'Europe/Berlin');

        $this->expectException(KsefNotAvailableException::class);

        $this->test($this->owner);
    }

    public function test_it_keeps_the_connection_from_employees(): void
    {
        $this->saveToken();

        foreach ([fn () => $this->view($this->employee), fn () => $this->test($this->employee)] as $attempt) {
            try {
                $attempt();
                self::fail('Exception expected.');
            } catch (KsefConnectionForbiddenException) {
            }
        }
        self::assertNull($this->connections->findCurrent()?->getLastCheckedAt());
    }

    public function test_it_shows_nothing_to_non_members_or_without_a_connection(): void
    {
        $stranger = UserMother::any('stranger@example.com');

        self::assertNull($this->view($this->owner));
        $this->saveToken();
        self::assertNull($this->view($stranger));
        self::assertNull(($this->targetHandler())(new GetKsefTokenTargetQuery($this->organization->getId()->toRfc4122(), $stranger->getId()->toRfc4122(), true)));

        $this->expectException(OrganizationNotFoundException::class);

        $this->test($stranger);
    }

    #[DataProvider('managers')]
    public function test_it_queues_the_import_when_an_owner_or_manager_asks_for_it(string $actor): void
    {
        $this->saveToken();

        $this->requestImport($this->user($actor));

        $organizationId = $this->organization->getId()->toRfc4122();
        self::assertEquals([['job' => new ImportKsefInvoicesJob($organizationId), 'deduplicationKey' => 'ksef-import:'.$organizationId]], $this->jobBus->dispatched);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->view($this->owner)?->lastImportRequestedAt);
    }

    public function test_it_accepts_import_now_again_only_after_15_minutes(): void
    {
        $this->saveToken();
        $this->requestImport($this->owner);
        $this->clock->modify('+14 minutes');

        try {
            $this->requestImport($this->manager);
            self::fail('Exception expected.');
        } catch (KsefImportTooSoonException) {
        }
        self::assertCount(1, $this->jobBus->dispatched);

        $this->clock->modify('+1 minute');
        $this->requestImport($this->manager);
        self::assertCount(2, $this->jobBus->dispatched);
    }

    public function test_it_refuses_import_now_to_employees(): void
    {
        $this->saveToken();

        $this->expectException(KsefConnectionForbiddenException::class);

        $this->requestImport($this->employee);
    }

    public function test_it_refuses_import_now_without_a_connection_a_token_or_with_a_rejected_one(): void
    {
        $attempts = [
            KsefConnectionNotFoundException::class => function (): void {
            },
            KsefTokenRejectedException::class => function (): void {
                $this->saveToken('a-token-ksef-refuses');
                $this->test($this->owner);
            },
            KsefTokenNotSetException::class => function (): void {
                $this->removeToken();
            },
        ];

        foreach ($attempts as $expected => $arrange) {
            $arrange();
            try {
                $this->requestImport($this->owner);
                self::fail(\sprintf('%s expected.', $expected));
            } catch (KsefException $e) {
                self::assertInstanceOf($expected, $e);
            }
        }
        self::assertSame([], $this->jobBus->dispatched);
    }

    #[DataProvider('managers')]
    public function test_it_lets_owners_and_managers_choose_the_start_date(string $actor): void
    {
        $this->saveToken();
        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertNull($view->syncStartDate);
        self::assertSame('2026-10-01', $view->defaultSyncStartDate);

        $this->changeSyncStartDate($this->user($actor), '2026-02-01');

        $view = $this->view($this->owner) ?? self::fail('Connection expected.');
        self::assertSame('2026-02-01', $view->syncStartDate);
        self::assertSame('2026-10-01', $view->defaultSyncStartDate);

        $this->changeSyncStartDate($this->user($actor), null);

        self::assertNull(($this->view($this->owner) ?? self::fail('Connection expected.'))->syncStartDate);
    }

    public function test_it_refuses_a_start_date_in_the_future_in_the_organizations_time_zone(): void
    {
        $this->saveToken();

        $this->expectException(InvalidSyncStartDateException::class);

        $this->changeSyncStartDate($this->owner, '2026-11-14');
    }

    public function test_it_refuses_employees_and_strangers_to_choose_the_start_date(): void
    {
        $this->saveToken();

        try {
            $this->changeSyncStartDate($this->employee, '2026-03-01');
            self::fail('Exception expected.');
        } catch (KsefConnectionForbiddenException) {
        }

        $this->expectException(OrganizationNotFoundException::class);

        $this->changeSyncStartDate(UserMother::any('stranger@example.com'), '2026-03-01');
    }

    public function test_it_reports_choosing_the_start_date_without_a_connection_as_not_found(): void
    {
        $this->expectException(KsefConnectionNotFoundException::class);

        $this->changeSyncStartDate($this->owner, '2026-03-01');
    }

    private function changeSyncStartDate(User $actor, ?string $date): void
    {
        (new ChangeKsefSyncStartDateHandler($this->connections, $this->queryBus, $this->clock))(
            new ChangeKsefSyncStartDateCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $date),
        );
    }

    private function requestImport(User $actor): void
    {
        (new RequestKsefImportHandler($this->connections, $this->queryBus, $this->jobBus, $this->clock))(
            new RequestKsefImportCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()),
        );
    }

    private function user(string $actor): User
    {
        return match ($actor) {
            'owner' => $this->owner,
            'manager' => $this->manager,
            'employee' => $this->employee,
            default => throw new \LogicException($actor),
        };
    }

    /** As SaveKsefTokenProcessor does it, as the owner. */
    private function saveToken(string $token = self::TOKEN): void
    {
        $target = $this->target($this->owner, true);
        $secretId = $target->secretId ?? Uuid::v7()->toRfc4122();
        (new SetOrganizationSecretHandler($this->secrets, $this->cipher, $this->queryBus, new RecordingEventPublisher(), $this->clock, new RecordingLogger()))(
            new SetOrganizationSecretCommand($secretId, $this->organization->getId()->toRfc4122(), SecretKind::KsefToken->value, $token, $this->owner->getId()->toRfc4122()),
        );
        $this->saveConnection($this->owner, $target->connectionId ?? Uuid::v7()->toRfc4122(), $secretId);
    }

    /** As RemoveKsefTokenProcessor does it, as the owner. */
    private function removeToken(): void
    {
        $secretId = $this->target($this->owner, false)->secretId ?? self::fail('Secret expected.');
        (new RemoveOrganizationSecretHandler($this->secrets, $this->queryBus, new RecordingEventPublisher(), new RecordingLogger()))(
            new RemoveOrganizationSecretCommand($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122(), $secretId),
        );
        $this->removeConnection($this->owner);
    }

    private function saveConnection(User $actor, string $connectionId, string $secretId): void
    {
        (new SaveKsefTokenHandler($this->connections, $this->queryBus, $this->clock))(
            new SaveKsefTokenCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $connectionId, $secretId),
        );
    }

    private function removeConnection(User $actor): void
    {
        (new RemoveKsefTokenHandler($this->connections, $this->queryBus))(
            new RemoveKsefTokenCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()),
        );
    }

    private function test(User $actor): void
    {
        (new TestKsefConnectionHandler($this->connections, $this->ksef, $this->queryBus, $this->clock))(
            new TestKsefConnectionCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()),
        );
    }

    private function view(User $actor): ?KsefConnectionView
    {
        return (new GetKsefConnectionHandler($this->connections, $this->queryBus, $this->clock))(
            new GetKsefConnectionQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()),
        );
    }

    private function target(User $actor, bool $toSave): KsefTokenTargetView
    {
        return ($this->targetHandler())(new GetKsefTokenTargetQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $toSave))
            ?? self::fail('Target expected.');
    }

    private function targetHandler(): GetKsefTokenTargetHandler
    {
        return new GetKsefTokenTargetHandler($this->connections, $this->queryBus);
    }
}
