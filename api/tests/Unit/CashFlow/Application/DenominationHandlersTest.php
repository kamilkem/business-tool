<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\Command\AddDenomination\AddDenominationCommand;
use App\CashFlow\Application\Command\AddDenomination\AddDenominationHandler;
use App\CashFlow\Application\Command\DeactivateDenomination\DeactivateDenominationCommand;
use App\CashFlow\Application\Command\DeactivateDenomination\DeactivateDenominationHandler;
use App\CashFlow\Application\Command\DeleteDenomination\DeleteDenominationCommand;
use App\CashFlow\Application\Command\DeleteDenomination\DeleteDenominationHandler;
use App\CashFlow\Application\Command\ReactivateDenomination\ReactivateDenominationCommand;
use App\CashFlow\Application\Command\ReactivateDenomination\ReactivateDenominationHandler;
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use App\CashFlow\Application\Query\GetDenomination\GetDenominationHandler;
use App\CashFlow\Application\Query\GetDenomination\GetDenominationQuery;
use App\CashFlow\Application\Query\ListDenominations\ListDenominationsHandler;
use App\CashFlow\Application\Query\ListDenominations\ListDenominationsQuery;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization in Poland (PLN) with an owner, a manager, an employee and a former manager; the
 * Organization module answers through its real queries over an in-memory repository.
 */
#[CoversClass(AddDenominationHandler::class)]
#[CoversClass(DeactivateDenominationHandler::class)]
#[CoversClass(ReactivateDenominationHandler::class)]
#[CoversClass(DeleteDenominationHandler::class)]
#[CoversClass(GetDenominationHandler::class)]
#[CoversClass(ListDenominationsHandler::class)]
final class DenominationHandlersTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private InMemoryOrganizationRepository $organizations;
    private InMemoryDenominationRepository $denominations;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $formerManager;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->organizations = new InMemoryOrganizationRepository();
        $this->denominations = new InMemoryDenominationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->formerManager = UserMother::any('fred@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->formerManager, MembershipRole::Manager, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->formerManager);
        $this->organizations->add($this->organization);

        $organizations = $this->organizations;
        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    public function test_it_adds_a_denomination_in_the_organizations_currency(): void
    {
        $id = $this->add($this->manager, 500, 'coin');

        $denomination = $this->denominations->findById(Uuid::fromString($id));
        self::assertNotNull($denomination);
        self::assertSame('PLN', $denomination->getCurrency());
        self::assertSame(500, $denomination->getValue());
        self::assertSame(DenominationKind::Coin, $denomination->getKind());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $denomination->getCreatedAt());
    }

    public function test_it_refuses_a_value_the_organization_has_even_deactivated(): void
    {
        $id = $this->add($this->owner, 500, 'coin');
        $this->deactivate($this->owner, $id);

        $this->expectException(DenominationValueTakenException::class);

        $this->add($this->owner, 500, 'note');
    }

    public function test_it_refuses_employees_and_former_members_every_change(): void
    {
        $id = $this->add($this->owner, 500, 'coin');
        $attempts = [
            'add' => fn (User $user) => $this->add($user, 100, 'coin'),
            'deactivate' => fn (User $user) => $this->deactivate($user, $id),
            'reactivate' => fn (User $user) => $this->reactivate($user, $id),
            'delete' => fn (User $user) => $this->delete($user, $id),
        ];

        foreach ($attempts as $case => $attempt) {
            foreach ([CashFlowAccessForbiddenException::class => $this->employee, OrganizationNotFoundException::class => $this->formerManager] as $expected => $user) {
                try {
                    $attempt($user);
                    self::fail($case.' should be refused.');
                } catch (CashFlowAccessForbiddenException|OrganizationNotFoundException $e) {
                    self::assertInstanceOf($expected, $e, $case);
                }
            }
        }
        self::assertCount(1, $this->denominations->findByCurrency('PLN'));
    }

    public function test_it_deactivates_and_reactivates(): void
    {
        $id = $this->add($this->owner, 500, 'coin');

        $this->deactivate($this->manager, $id);
        self::assertFalse($this->get($this->owner, $id)?->active);
        self::assertSame([], $this->list($this->owner));

        $this->reactivate($this->manager, $id);
        self::assertTrue($this->get($this->owner, $id)?->active);
    }

    public function test_it_deletes_an_unused_denomination_and_refuses_a_used_one(): void
    {
        $unused = $this->add($this->owner, 100, 'coin');
        $used = $this->add($this->owner, 200, 'coin');
        $this->denominations->markUsed($this->denominations->findById(Uuid::fromString($used)) ?? throw new \LogicException());

        $this->delete($this->manager, $unused);
        self::assertNull($this->denominations->findById(Uuid::fromString($unused)));

        $this->expectException(DenominationInUseException::class);
        $this->delete($this->manager, $used);
    }

    public function test_it_reports_an_unknown_denomination(): void
    {
        foreach (['deactivate', 'reactivate', 'delete'] as $operation) {
            foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
                try {
                    $this->{$operation}($this->owner, $id);
                    self::fail($operation.' should not find the denomination.');
                } catch (DenominationNotFoundException $e) {
                    self::assertSame(['denominationId' => $id], $e->errorParameters());
                }
            }
        }
    }

    public function test_it_lets_every_active_member_read_the_denominations_of_the_current_currency(): void
    {
        $five = $this->add($this->owner, 500, 'coin');
        $ten = $this->add($this->owner, 1_000, 'note');
        $one = $this->add($this->owner, 100, 'coin');
        $this->deactivate($this->owner, $one);
        $this->denominations->markUsed($this->denominations->findById(Uuid::fromString($ten)) ?? throw new \LogicException());
        // A denomination of another currency (the organization used to keep EUR) is not listed.
        $this->denominations->add(Denomination::create(Uuid::v7(), 'EUR', 200, DenominationKind::Coin, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW)));

        self::assertSame([[$five, 500, false], [$ten, 1_000, true]], self::summary($this->list($this->employee)));
        self::assertSame([[$five, 500, false], [$ten, 1_000, true], [$one, 100, false]], self::summary($this->list($this->manager, true)));
        self::assertSame('note', $this->get($this->employee, $ten)?->kind);
        self::assertTrue($this->get($this->employee, $ten)->used);
    }

    public function test_it_returns_null_to_someone_who_is_not_an_active_member(): void
    {
        $id = $this->add($this->owner, 500, 'coin');

        self::assertNull($this->list($this->formerManager));
        self::assertNull($this->get($this->formerManager, $id));
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
    }

    private function add(User $actor, int $value, string $kind): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new AddDenominationHandler($this->denominations, $this->queryBus, $this->clock))(new AddDenominationCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $value, $kind));

        return $id;
    }

    private function deactivate(User $actor, string $id): void
    {
        (new DeactivateDenominationHandler($this->denominations, $this->queryBus, $this->clock))(new DeactivateDenominationCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function reactivate(User $actor, string $id): void
    {
        (new ReactivateDenominationHandler($this->denominations, $this->queryBus))(new ReactivateDenominationCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function delete(User $actor, string $id): void
    {
        (new DeleteDenominationHandler($this->denominations, $this->queryBus))(new DeleteDenominationCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function get(User $actor, string $id): ?DenominationView
    {
        return (new GetDenominationHandler($this->denominations, $this->queryBus))(new GetDenominationQuery($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    /** @return list<DenominationView>|null */
    private function list(User $actor, bool $includeDeactivated = false): ?array
    {
        return (new ListDenominationsHandler($this->denominations, $this->queryBus))(new ListDenominationsQuery($this->organizationId(), $actor->getId()->toRfc4122(), $includeDeactivated));
    }

    /**
     * @param list<DenominationView>|null $views
     *
     * @return list<array{string, int, bool}>
     */
    private static function summary(?array $views): array
    {
        return array_map(static fn (DenominationView $view): array => [$view->id, $view->value, $view->used], $views ?? []);
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }
}
