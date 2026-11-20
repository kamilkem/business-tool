<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Secret\Application\Command\ReEncryptOrganizationSecret\ReEncryptOrganizationSecretCommand;
use App\Secret\Application\Command\ReEncryptOrganizationSecret\ReEncryptOrganizationSecretHandler;
use App\Secret\Application\Command\RemoveOrganizationSecret\RemoveOrganizationSecretCommand;
use App\Secret\Application\Command\RemoveOrganizationSecret\RemoveOrganizationSecretHandler;
use App\Secret\Application\Command\SetOrganizationSecret\SetOrganizationSecretCommand;
use App\Secret\Application\Command\SetOrganizationSecret\SetOrganizationSecretHandler;
use App\Secret\Application\Query\GetOrganizationSecret\GetOrganizationSecretHandler;
use App\Secret\Application\Query\GetOrganizationSecret\GetOrganizationSecretQuery;
use App\Secret\Application\Query\GetOrganizationSecret\OrganizationSecretView;
use App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt\ListOrganizationSecretsToReEncryptHandler;
use App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt\ListOrganizationSecretsToReEncryptQuery;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealedSecretView;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretHandler;
use App\Secret\Application\Query\RevealOrganizationSecret\RevealOrganizationSecretQuery;
use App\Secret\Application\SecretActor;
use App\Secret\Domain\Event\OrganizationSecretRemoved;
use App\Secret\Domain\Event\OrganizationSecretSet;
use App\Secret\Domain\Exception\OrganizationSecretNotFoundException;
use App\Secret\Domain\Exception\SecretManagementForbiddenException;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Domain\SecretCipherInterface;
use App\Secret\Infrastructure\Sodium\SecretKeyring;
use App\Secret\Infrastructure\Sodium\SodiumSecretCipher;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\RecordingLogger;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization with an owner, a manager and an employee; the real sodium cipher with a test key
 * (adr/0026 §8) and an in-memory repository.
 */
#[CoversClass(SetOrganizationSecretHandler::class)]
#[CoversClass(RemoveOrganizationSecretHandler::class)]
#[CoversClass(ReEncryptOrganizationSecretHandler::class)]
#[CoversClass(GetOrganizationSecretHandler::class)]
#[CoversClass(RevealOrganizationSecretHandler::class)]
#[CoversClass(ListOrganizationSecretsToReEncryptHandler::class)]
#[CoversClass(OrganizationSecretView::class)]
#[CoversClass(RevealedSecretView::class)]
#[CoversClass(SecretActor::class)]
#[CoversClass(OrganizationSecret::class)]
final class OrganizationSecretHandlersTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string TOKEN = 'fake-ksef-token|nip-1111111111|not-a-real-token';

    private MockClock $clock;
    private InMemoryOrganizationSecretRepository $secrets;
    private StubQueryBus $queryBus;
    private RecordingEventPublisher $events;
    private RecordingLogger $logger;
    private SecretCipherInterface $cipher;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->secrets = new InMemoryOrganizationSecretRepository();
        $this->events = new RecordingEventPublisher();
        $this->logger = new RecordingLogger();
        $this->cipher = self::cipher(self::key('old', 'a'));
        $organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    private static function key(string $keyId, string $fill): string
    {
        return $keyId.':'.base64_encode(str_repeat($fill, SecretKeyring::keyLength()));
    }

    private static function cipher(string $keyring): SodiumSecretCipher
    {
        return new SodiumSecretCipher($keyring);
    }

    public function test_it_stores_an_owners_secret_encrypted_and_reveals_it(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->set($this->owner, $id);

        $secret = $this->secrets->findById(Uuid::fromString($id)) ?? self::fail('Secret expected.');
        self::assertSame('old', $secret->getKeyId());
        self::assertTrue($secret->getSetBy()->equals($this->owner->getId()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $secret->getSetAt());
        self::assertStringNotContainsString(self::TOKEN, print_r($secret, true));
        self::assertStringNotContainsString(self::TOKEN, (string) base64_decode($secret->getEncryptedValue()->ciphertext, true));

        $revealed = $this->reveal($id);
        self::assertNotNull($revealed);
        self::assertSame(self::TOKEN, $revealed->value);
        self::assertSame('ksef_token', $revealed->kind);
        self::assertStringNotContainsString(self::TOKEN, print_r($revealed, true));
    }

    public function test_it_replaces_the_value_in_place(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);
        $otherOwner = UserMother::any('oscar@example.com');
        OrganizationMother::addMember($this->organization, $otherOwner, MembershipRole::Owner, new \DateTimeImmutable('2026-01-05 09:00:00'));
        $this->clock->modify('+1 hour');

        $this->set($otherOwner, $id, 'another-fake-token');

        self::assertSame('another-fake-token', $this->reveal($id)?->value);
        $view = $this->metadata($id) ?? self::fail('Metadata expected.');
        self::assertSame($id, $view->id);
        self::assertSame($otherOwner->getId()->toRfc4122(), $view->setBy);
        self::assertEquals(new \DateTimeImmutable('2026-11-13 11:00:00'), $view->setAt);
    }

    public function test_it_publishes_and_logs_ids_never_the_value(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->set($this->owner, $id);
        $this->remove($this->owner, $id);

        $organizationId = $this->organization->getId()->toRfc4122();
        $ownerId = $this->owner->getId()->toRfc4122();
        self::assertEquals([
            new OrganizationSecretSet($organizationId, $id, 'ksef_token', 'old', $ownerId),
            new OrganizationSecretRemoved($organizationId, $id, 'ksef_token', $ownerId),
        ], $this->events->events);
        self::assertSame(['Organization secret set.', 'Organization secret removed.'], $this->logger->messages(LogLevel::INFO));
        self::assertStringNotContainsString(self::TOKEN, $this->logger->dump());
    }

    /** @return iterable<string, array{string}> */
    public static function nonOwners(): iterable
    {
        yield 'manager' => ['manager'];
        yield 'employee' => ['employee'];
    }

    #[DataProvider('nonOwners')]
    public function test_it_lets_only_owners_set_a_secret(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        try {
            $this->set($this->user($actor), $id);
            self::fail('Exception expected.');
        } catch (SecretManagementForbiddenException) {
        }

        self::assertNull($this->metadata($id));
        self::assertSame([], $this->events->events);
    }

    #[DataProvider('nonOwners')]
    public function test_it_lets_only_owners_replace_or_remove_a_secret(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);

        foreach ([fn () => $this->set($this->user($actor), $id, 'other'), fn () => $this->remove($this->user($actor), $id)] as $attempt) {
            try {
                $attempt();
                self::fail('Exception expected.');
            } catch (SecretManagementForbiddenException) {
            }
        }

        self::assertSame(self::TOKEN, $this->reveal($id)?->value);
    }

    public function test_it_reports_a_non_member_as_not_found(): void
    {
        $stranger = UserMother::any('stranger@example.com');

        $this->expectException(OrganizationNotFoundException::class);

        $this->set($stranger, Uuid::v7()->toRfc4122());
    }

    public function test_it_reports_a_deactivated_owner_as_not_found(): void
    {
        $formerOwner = UserMother::any('former@example.com');
        OrganizationMother::addMember($this->organization, $formerOwner, MembershipRole::Owner, new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::deactivateMember($this->organization, $formerOwner, new \DateTimeImmutable('2026-02-01 09:00:00'));

        $this->expectException(OrganizationNotFoundException::class);

        $this->set($formerOwner, Uuid::v7()->toRfc4122());
    }

    public function test_it_removes_a_secret(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);

        $this->remove($this->owner, $id);

        self::assertNull($this->metadata($id));
        self::assertNull($this->reveal($id));
    }

    public function test_it_reports_removing_an_unknown_secret_as_not_found(): void
    {
        $this->expectException(OrganizationSecretNotFoundException::class);

        $this->remove($this->owner, 'not-a-uuid');
    }

    public function test_it_returns_metadata_without_the_value(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);

        $view = $this->metadata($id);

        self::assertEquals(new OrganizationSecretView($id, 'ksef_token', new \DateTimeImmutable(self::NOW), $this->owner->getId()->toRfc4122()), $view);
        self::assertNull($this->metadata(Uuid::v7()->toRfc4122()));
    }

    public function test_it_reveals_nothing_for_another_kind_or_an_unknown_secret(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);

        self::assertNull(($this->revealHandler())(new RevealOrganizationSecretQuery($this->organization->getId()->toRfc4122(), $id, 'ksef_certificate')));
        self::assertNull($this->reveal(Uuid::v7()->toRfc4122()));
        self::assertNull($this->reveal('not-a-uuid'));
    }

    public function test_it_reports_and_logs_a_secret_whose_key_left_the_keyring(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);
        $this->cipher = self::cipher(self::key('new', 'b'));

        try {
            $this->reveal($id);
            self::fail('Exception expected.');
        } catch (SecretUnreadableException $e) {
            self::assertStringContainsString($id, $e->getMessage());
        }

        self::assertSame(['Organization secret cannot be read.'], $this->logger->messages(LogLevel::ERROR));
        self::assertStringNotContainsString(self::TOKEN, $this->logger->dump());
    }

    public function test_it_refuses_a_value_copied_from_another_secret(): void
    {
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $first);
        $this->set($this->owner, $second, 'another-fake-token');
        $copied = $this->secrets->findById(Uuid::fromString($first)) ?? self::fail('Secret expected.');
        ($this->secrets->findById(Uuid::fromString($second)) ?? self::fail('Secret expected.'))->reEncrypt($copied->getEncryptedValue());

        $this->expectException(SecretUnreadableException::class);

        $this->reveal($second);
    }

    public function test_it_re_encrypts_secrets_under_an_old_key_with_the_current_one(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);
        $this->cipher = self::cipher(self::key('new', 'b').','.self::key('old', 'a'));

        self::assertSame([$id], $this->toReEncrypt());
        $this->reEncrypt($id);

        $secret = $this->secrets->findById(Uuid::fromString($id)) ?? self::fail('Secret expected.');
        self::assertSame('new', $secret->getKeyId());
        self::assertSame([], $this->toReEncrypt());
        self::assertTrue($secret->getSetBy()->equals($this->owner->getId()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $secret->getSetAt());
        $this->cipher = self::cipher(self::key('new', 'b'));
        self::assertSame(self::TOKEN, $this->reveal($id)?->value);
        self::assertSame(['Organization secret set.', 'Organization secret re-encrypted.'], $this->logger->messages(LogLevel::INFO));
    }

    public function test_it_leaves_a_secret_already_under_the_current_key_as_it_is(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);
        $before = $this->secrets->findById(Uuid::fromString($id))?->getEncryptedValue();

        $this->reEncrypt($id);

        self::assertEquals($before, $this->secrets->findById(Uuid::fromString($id))?->getEncryptedValue());
        self::assertSame(['Organization secret set.'], $this->logger->messages(LogLevel::INFO));
    }

    public function test_it_reports_a_secret_that_cannot_be_re_encrypted(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->set($this->owner, $id);
        $this->cipher = self::cipher(self::key('newer', 'c').','.self::key('new', 'b'));

        try {
            $this->reEncrypt($id);
            self::fail('Exception expected.');
        } catch (SecretUnreadableException) {
        }

        self::assertSame('old', $this->secrets->findById(Uuid::fromString($id))?->getKeyId());
        self::assertSame(['Organization secret cannot be read for re-encryption.'], $this->logger->messages(LogLevel::ERROR));
    }

    public function test_it_reports_re_encrypting_an_unknown_secret_as_not_found(): void
    {
        $this->expectException(OrganizationSecretNotFoundException::class);

        $this->reEncrypt(Uuid::v7()->toRfc4122());
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

    private function set(User $actor, string $id, string $value = self::TOKEN): void
    {
        (new SetOrganizationSecretHandler($this->secrets, $this->cipher, $this->queryBus, $this->events, $this->clock, $this->logger))(
            new SetOrganizationSecretCommand($id, $this->organization->getId()->toRfc4122(), SecretKind::KsefToken->value, $value, $actor->getId()->toRfc4122()),
        );
    }

    private function remove(User $actor, string $id): void
    {
        (new RemoveOrganizationSecretHandler($this->secrets, $this->queryBus, $this->events, $this->logger))(
            new RemoveOrganizationSecretCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function reEncrypt(string $id): void
    {
        (new ReEncryptOrganizationSecretHandler($this->secrets, $this->cipher, $this->logger))(
            new ReEncryptOrganizationSecretCommand($this->organization->getId()->toRfc4122(), $id),
        );
    }

    private function metadata(string $id): ?OrganizationSecretView
    {
        return (new GetOrganizationSecretHandler($this->secrets))(new GetOrganizationSecretQuery($this->organization->getId()->toRfc4122(), $id));
    }

    private function reveal(string $id): ?RevealedSecretView
    {
        return ($this->revealHandler())(new RevealOrganizationSecretQuery($this->organization->getId()->toRfc4122(), $id, SecretKind::KsefToken->value));
    }

    private function revealHandler(): RevealOrganizationSecretHandler
    {
        return new RevealOrganizationSecretHandler($this->secrets, $this->cipher, $this->logger);
    }

    /** @return list<string> */
    private function toReEncrypt(): array
    {
        return (new ListOrganizationSecretsToReEncryptHandler($this->secrets, $this->cipher))(new ListOrganizationSecretsToReEncryptQuery($this->organization->getId()->toRfc4122()));
    }
}
