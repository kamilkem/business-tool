<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\Command\AddCashStorage\AddCashStorageCommand;
use App\CashFlow\Application\Command\AddCashStorage\AddCashStorageHandler;
use App\CashFlow\Application\Command\ChangeCashStorage\ChangeCashStorageCommand;
use App\CashFlow\Application\Command\ChangeCashStorage\ChangeCashStorageHandler;
use App\CashFlow\Application\Command\DeactivateCashStorage\DeactivateCashStorageCommand;
use App\CashFlow\Application\Command\DeactivateCashStorage\DeactivateCashStorageHandler;
use App\CashFlow\Application\Command\DeleteCashStorage\DeleteCashStorageCommand;
use App\CashFlow\Application\Command\DeleteCashStorage\DeleteCashStorageHandler;
use App\CashFlow\Application\Command\ReactivateCashStorage\ReactivateCashStorageCommand;
use App\CashFlow\Application\Command\ReactivateCashStorage\ReactivateCashStorageHandler;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageHandler;
use App\CashFlow\Application\Query\GetCashStorage\GetCashStorageQuery;
use App\CashFlow\Application\Query\ListCashStorages\ListCashStoragesHandler;
use App\CashFlow\Application\Query\ListCashStorages\ListCashStoragesQuery;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashStorageMode;
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
 * An organization with an owner, a manager, an employee and a former manager; the Organization
 * module answers through its real queries over an in-memory repository.
 */
#[CoversClass(AddCashStorageHandler::class)]
#[CoversClass(ChangeCashStorageHandler::class)]
#[CoversClass(DeactivateCashStorageHandler::class)]
#[CoversClass(ReactivateCashStorageHandler::class)]
#[CoversClass(DeleteCashStorageHandler::class)]
#[CoversClass(GetCashStorageHandler::class)]
#[CoversClass(ListCashStoragesHandler::class)]
final class CashStorageHandlersTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private InMemoryOrganizationRepository $organizations;
    private InMemoryCashStorageRepository $storages;
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
        $this->storages = new InMemoryCashStorageRepository();
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

    public function test_it_adds_a_storage(): void
    {
        $id = $this->add($this->manager, ' Kasetka ', 'counted');

        $storage = $this->storages->findById(Uuid::fromString($id));
        self::assertNotNull($storage);
        self::assertSame('Kasetka', $storage->getName());
        self::assertSame(CashStorageMode::Counted, $storage->getMode());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $storage->getCreatedAt());
    }

    public function test_it_refuses_a_name_the_organization_has_even_deactivated(): void
    {
        $id = $this->add($this->owner, 'Sejf', 'deposit');
        $this->deactivate($this->owner, $id);

        $this->expectException(StorageNameTakenException::class);

        $this->add($this->owner, '  Sejf', 'counted');
    }

    public function test_it_renames_and_changes_the_mode_in_one_change(): void
    {
        $id = $this->add($this->owner, 'Kasetka', 'counted');

        $this->change($this->manager, $id, ' Kasetka bar ', 'deposit');

        $view = $this->get($this->owner, $id);
        self::assertSame(['Kasetka bar', 'deposit'], [$view?->name, $view?->mode]);
    }

    public function test_it_keeps_the_name_when_only_the_mode_changes_and_refuses_another_storages_name(): void
    {
        $box = $this->add($this->owner, 'Kasetka', 'counted');
        $this->add($this->owner, 'Sejf', 'deposit');

        $this->change($this->owner, $box, 'Kasetka', 'deposit');
        self::assertSame('deposit', $this->get($this->owner, $box)?->mode);

        try {
            $this->change($this->owner, $box, 'Sejf', 'counted');
            self::fail('The name should be taken.');
        } catch (StorageNameTakenException) {
        }
        // Nothing of the refused change was applied.
        self::assertSame(['Kasetka', 'deposit'], [$this->get($this->owner, $box)?->name, $this->get($this->owner, $box)?->mode]);
    }

    public function test_it_refuses_employees_and_former_members_every_change(): void
    {
        $id = $this->add($this->owner, 'Sejf', 'deposit');
        $attempts = [
            'add' => fn (User $user) => $this->add($user, 'Kasetka', 'counted'),
            'change' => fn (User $user) => $this->change($user, $id, 'Kasetka', 'counted'),
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
                    self::assertSame($expected, $e::class, $case);
                }
            }
        }
        self::assertSame([['Sejf', 'deposit', true]], array_map(static fn (CashStorageView $v): array => [$v->name, $v->mode, $v->active], $this->list($this->owner, true) ?? []));
    }

    public function test_it_deactivates_and_reactivates(): void
    {
        $id = $this->add($this->owner, 'Sejf', 'deposit');

        $this->deactivate($this->manager, $id);
        self::assertFalse($this->get($this->owner, $id)?->active);
        self::assertSame([], $this->list($this->owner));

        $this->reactivate($this->manager, $id);
        self::assertTrue($this->get($this->owner, $id)?->active);
    }

    public function test_it_deletes_an_unused_storage_and_refuses_a_used_one(): void
    {
        $unused = $this->add($this->owner, 'Kasetka', 'counted');
        $used = $this->add($this->owner, 'Sejf', 'deposit');
        $this->storages->markUsed($this->storages->findById(Uuid::fromString($used)) ?? throw new \LogicException());

        $this->delete($this->manager, $unused);
        self::assertNull($this->storages->findById(Uuid::fromString($unused)));

        $this->expectException(StorageInUseException::class);
        $this->delete($this->manager, $used);
    }

    public function test_it_reports_an_unknown_storage(): void
    {
        foreach (['change', 'deactivate', 'reactivate', 'delete'] as $operation) {
            foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
                try {
                    'change' === $operation ? $this->change($this->owner, $id, 'Sejf', 'deposit') : $this->{$operation}($this->owner, $id);
                    self::fail($operation.' should not find the storage.');
                } catch (StorageNotFoundException $e) {
                    self::assertSame(['storageId' => $id], $e->errorParameters());
                }
            }
        }
    }

    public function test_it_lets_every_active_member_read_the_storages(): void
    {
        $safe = $this->add($this->owner, 'Sejf', 'deposit');
        $box = $this->add($this->owner, 'Kasetka', 'counted');
        $old = $this->add($this->owner, 'Archive', 'deposit');
        $this->deactivate($this->owner, $old);
        $this->storages->markUsed($this->storages->findById(Uuid::fromString($safe)) ?? throw new \LogicException());

        self::assertSame([[$box, 'Kasetka', 'counted', false], [$safe, 'Sejf', 'deposit', true]], self::summary($this->list($this->employee)));
        self::assertSame([$box, $safe, $old], array_map(static fn (CashStorageView $v): string => $v->id, $this->list($this->manager, true) ?? []));
        self::assertSame('deposit', $this->get($this->employee, $safe)?->mode);
        self::assertTrue($this->get($this->employee, $safe)->used);
    }

    public function test_it_refuses_an_employee_reading_a_storage_to_change_it(): void
    {
        $id = $this->add($this->owner, 'Sejf', 'deposit');

        self::assertSame('Sejf', $this->get($this->manager, $id, toManage: true)?->name);

        $this->expectException(CashFlowAccessForbiddenException::class);
        $this->get($this->employee, $id, toManage: true);
    }

    public function test_it_returns_null_to_someone_who_is_not_an_active_member(): void
    {
        $id = $this->add($this->owner, 'Sejf', 'deposit');

        self::assertNull($this->list($this->formerManager));
        self::assertNull($this->get($this->formerManager, $id));
        self::assertNull($this->get($this->formerManager, $id, toManage: true));
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));
    }

    private function add(User $actor, string $name, string $mode): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new AddCashStorageHandler($this->storages, $this->queryBus, $this->clock))(new AddCashStorageCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name, $mode));

        return $id;
    }

    private function change(User $actor, string $id, string $name, string $mode): void
    {
        (new ChangeCashStorageHandler($this->storages, $this->queryBus))(new ChangeCashStorageCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name, $mode));
    }

    private function deactivate(User $actor, string $id): void
    {
        (new DeactivateCashStorageHandler($this->storages, $this->queryBus, $this->clock))(new DeactivateCashStorageCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function reactivate(User $actor, string $id): void
    {
        (new ReactivateCashStorageHandler($this->storages, $this->queryBus))(new ReactivateCashStorageCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function delete(User $actor, string $id): void
    {
        (new DeleteCashStorageHandler($this->storages, $this->queryBus))(new DeleteCashStorageCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id));
    }

    private function get(User $actor, string $id, bool $toManage = false): ?CashStorageView
    {
        return (new GetCashStorageHandler($this->storages, $this->queryBus))(new GetCashStorageQuery($this->organizationId(), $actor->getId()->toRfc4122(), $id, $toManage));
    }

    /** @return list<CashStorageView>|null */
    private function list(User $actor, bool $includeDeactivated = false): ?array
    {
        return (new ListCashStoragesHandler($this->storages, $this->queryBus))(new ListCashStoragesQuery($this->organizationId(), $actor->getId()->toRfc4122(), $includeDeactivated));
    }

    /**
     * @param list<CashStorageView>|null $views
     *
     * @return list<array{string, string, string, bool}>
     */
    private static function summary(?array $views): array
    {
        return array_map(static fn (CashStorageView $view): array => [$view->id, $view->name, $view->mode, $view->used], $views ?? []);
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }
}
