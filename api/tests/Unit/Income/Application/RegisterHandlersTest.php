<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\AddRegister\AddRegisterCommand;
use App\Income\Application\Command\AddRegister\AddRegisterHandler;
use App\Income\Application\Command\DeactivateRegister\DeactivateRegisterCommand;
use App\Income\Application\Command\DeactivateRegister\DeactivateRegisterHandler;
use App\Income\Application\Command\RenameRegister\RenameRegisterCommand;
use App\Income\Application\Command\RenameRegister\RenameRegisterHandler;
use App\Income\Application\Query\GetRegister\GetRegisterHandler;
use App\Income\Application\Query\GetRegister\GetRegisterQuery;
use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Application\Query\ListRegisters\ListRegistersHandler;
use App\Income\Application\Query\ListRegisters\ListRegistersQuery;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidRegisterNameException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddRegisterHandler::class)]
#[CoversClass(RenameRegisterHandler::class)]
#[CoversClass(DeactivateRegisterHandler::class)]
#[CoversClass(GetRegisterHandler::class)]
#[CoversClass(ListRegistersHandler::class)]
#[CoversClass(RegisterView::class)]
final class RegisterHandlersTest extends IncomeHandlerTestCase
{
    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_adds_a_register_for_an_owner_or_manager(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->add($this->user($actor), $id, '  Kitchen ');

        $register = $this->registers->findById(Uuid::fromString($id));
        self::assertNotNull($register);
        self::assertSame('Kitchen', $register->getName());
        self::assertTrue($register->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $register->getCreatedAt());
    }

    public function test_it_refuses_a_name_another_register_has_even_deactivated(): void
    {
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Terrace');
        $terrace = $this->registers->findByName('Terrace') ?? self::fail('Register expected.');
        $this->deactivate($this->owner, $terrace->getId()->toRfc4122());

        foreach (['Bar', ' Bar ', 'Terrace'] as $name) {
            try {
                $this->add($this->owner, Uuid::v7()->toRfc4122(), $name);
                self::fail(\sprintf('"%s" should be taken.', $name));
            } catch (RegisterNameTakenException) {
            }
        }

        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'bar');
        self::assertCount(3, $this->registers->findAll(includeDeactivated: true));
    }

    public function test_it_refuses_a_blank_name(): void
    {
        $this->expectException(InvalidRegisterNameException::class);

        $this->add($this->owner, Uuid::v7()->toRfc4122(), '   ');
    }

    public function test_it_refuses_adding_to_an_employee(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        $this->add($this->employee, Uuid::v7()->toRfc4122(), 'Kitchen');
    }

    public function test_it_refuses_adding_to_a_deactivated_member(): void
    {
        $former = $this->deactivatedManager();

        $this->expectException(OrganizationNotFoundException::class);

        $this->add($former, Uuid::v7()->toRfc4122(), 'Kitchen');
    }

    #[DataProvider('managers')]
    public function test_it_renames_a_register(string $actor): void
    {
        $this->rename($this->user($actor), $this->bar->getId()->toRfc4122(), ' Main bar ');

        self::assertSame('Main bar', $this->bar->getName());
    }

    public function test_it_renames_a_register_to_its_own_name_and_a_deactivated_one(): void
    {
        $this->deactivate($this->owner, $this->bar->getId()->toRfc4122());

        $this->rename($this->owner, $this->bar->getId()->toRfc4122(), 'Bar');
        self::assertSame('Bar', $this->bar->getName());

        $this->rename($this->owner, $this->bar->getId()->toRfc4122(), 'Old bar');
        self::assertSame('Old bar', $this->bar->getName());
    }

    public function test_it_refuses_renaming_to_another_registers_name(): void
    {
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Kitchen');

        $this->expectException(RegisterNameTakenException::class);

        $this->rename($this->owner, $this->bar->getId()->toRfc4122(), 'Kitchen');
    }

    public function test_it_refuses_renaming_to_an_employee_before_looking_for_the_register(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        $this->rename($this->employee, Uuid::v7()->toRfc4122(), 'Kitchen');
    }

    /** @return iterable<string, array{string}> */
    public static function unknownIds(): iterable
    {
        yield 'unknown' => [Uuid::v7()->toRfc4122()];
        yield 'not a UUID' => ['bar'];
    }

    #[DataProvider('unknownIds')]
    public function test_it_reports_an_unknown_register_as_not_found(string $id): void
    {
        foreach ([fn () => $this->rename($this->owner, $id, 'Kitchen'), fn () => $this->deactivate($this->owner, $id)] as $action) {
            try {
                $action();
                self::fail('Not found expected.');
            } catch (RegisterNotFoundException) {
            }
        }

        self::assertNull($this->get($this->owner, $id));
    }

    #[DataProvider('managers')]
    public function test_it_deactivates_a_register_once(string $actor): void
    {
        $this->deactivate($this->user($actor), $this->bar->getId()->toRfc4122());

        self::assertFalse($this->bar->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->bar->getDeactivatedAt());

        $this->clock->modify('+1 day');
        $this->deactivate($this->owner, $this->bar->getId()->toRfc4122());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->bar->getDeactivatedAt());
    }

    public function test_it_refuses_deactivating_to_an_employee(): void
    {
        try {
            $this->deactivate($this->employee, $this->bar->getId()->toRfc4122());
            self::fail('Forbidden expected.');
        } catch (IncomeManagementForbiddenException) {
        }

        self::assertTrue($this->bar->isActive());
    }

    public function test_it_returns_a_register(): void
    {
        self::assertEquals(
            new RegisterView($this->bar->getId()->toRfc4122(), 'Bar', true, new \DateTimeImmutable('2026-01-05 09:00:00'), null),
            $this->get($this->manager, $this->bar->getId()->toRfc4122()),
        );

        $this->deactivate($this->owner, $this->bar->getId()->toRfc4122());

        self::assertEquals(
            new RegisterView($this->bar->getId()->toRfc4122(), 'Bar', false, new \DateTimeImmutable('2026-01-05 09:00:00'), new \DateTimeImmutable(self::NOW)),
            $this->get($this->manager, $this->bar->getId()->toRfc4122()),
        );
    }

    public function test_it_lists_active_registers_by_name_and_the_deactivated_ones_last_when_asked(): void
    {
        foreach (['Terrace', 'Kitchen', 'Garden'] as $name) {
            $this->add($this->owner, Uuid::v7()->toRfc4122(), $name);
        }
        $this->deactivate($this->owner, ($this->registers->findByName('Garden') ?? self::fail('Register expected.'))->getId()->toRfc4122());

        self::assertSame(['Bar', 'Kitchen', 'Terrace'], $this->names($this->list($this->manager)));
        self::assertSame(['Bar', 'Kitchen', 'Terrace', 'Garden'], $this->names($this->list($this->manager, includeDeactivated: true)));
    }

    public function test_it_returns_null_to_a_non_member_or_deactivated_member(): void
    {
        $former = $this->deactivatedManager();

        foreach ([$former, UserMother::any('stranger@example.com')] as $user) {
            self::assertNull($this->list($user));
            self::assertNull($this->get($user, $this->bar->getId()->toRfc4122()));
        }
    }

    public function test_it_refuses_reading_registers_to_an_employee(): void
    {
        $refused = 0;
        foreach ([fn () => $this->list($this->employee), fn () => $this->get($this->employee, $this->bar->getId()->toRfc4122())] as $read) {
            try {
                $read();
            } catch (IncomeManagementForbiddenException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function deactivatedManager(): User
    {
        $former = UserMother::any('dora@example.com');
        OrganizationMother::addMember($this->organization, $former, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $former, new \DateTimeImmutable('2026-11-01'));

        return $former;
    }

    private function add(User $actor, string $id, string $name): void
    {
        (new AddRegisterHandler($this->registers, $this->queryBus, $this->clock))(
            new AddRegisterCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name),
        );
    }

    private function rename(User $actor, string $id, string $name): void
    {
        (new RenameRegisterHandler($this->registers, $this->queryBus))(
            new RenameRegisterCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name),
        );
    }

    private function deactivate(User $actor, string $id): void
    {
        (new DeactivateRegisterHandler($this->registers, $this->queryBus, $this->clock))(
            new DeactivateRegisterCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function get(User $actor, string $id): ?RegisterView
    {
        return (new GetRegisterHandler($this->registers, $this->queryBus))(
            new GetRegisterQuery($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<RegisterView>|null */
    private function list(User $actor, bool $includeDeactivated = false): ?array
    {
        return (new ListRegistersHandler($this->registers, $this->queryBus))(
            new ListRegistersQuery($this->organizationId(), $actor->getId()->toRfc4122(), $includeDeactivated),
        );
    }

    /**
     * @param list<RegisterView>|null $views
     *
     * @return list<string>
     */
    private function names(?array $views): array
    {
        return array_map(static fn (RegisterView $view): string => $view->name, $views ?? []);
    }
}
