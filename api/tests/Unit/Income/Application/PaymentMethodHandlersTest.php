<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\AddPaymentMethod\AddPaymentMethodCommand;
use App\Income\Application\Command\AddPaymentMethod\AddPaymentMethodHandler;
use App\Income\Application\Command\DeactivatePaymentMethod\DeactivatePaymentMethodCommand;
use App\Income\Application\Command\DeactivatePaymentMethod\DeactivatePaymentMethodHandler;
use App\Income\Application\Command\DeletePaymentMethod\DeletePaymentMethodCommand;
use App\Income\Application\Command\DeletePaymentMethod\DeletePaymentMethodHandler;
use App\Income\Application\Command\ReactivatePaymentMethod\ReactivatePaymentMethodCommand;
use App\Income\Application\Command\ReactivatePaymentMethod\ReactivatePaymentMethodHandler;
use App\Income\Application\Command\ReorderPaymentMethods\ReorderPaymentMethodsCommand;
use App\Income\Application\Command\ReorderPaymentMethods\ReorderPaymentMethodsHandler;
use App\Income\Application\Command\UpdatePaymentMethod\UpdatePaymentMethodCommand;
use App\Income\Application\Command\UpdatePaymentMethod\UpdatePaymentMethodHandler;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodHandler;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodQuery;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsHandler;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Income\Domain\Exception\PaymentMethodOrderMismatchException;
use App\Income\Domain\Model\PaymentMethod;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddPaymentMethodHandler::class)]
#[CoversClass(UpdatePaymentMethodHandler::class)]
#[CoversClass(DeactivatePaymentMethodHandler::class)]
#[CoversClass(ReactivatePaymentMethodHandler::class)]
#[CoversClass(DeletePaymentMethodHandler::class)]
#[CoversClass(ReorderPaymentMethodsHandler::class)]
#[CoversClass(GetPaymentMethodHandler::class)]
#[CoversClass(ListPaymentMethodsHandler::class)]
#[CoversClass(PaymentMethodView::class)]
final class PaymentMethodHandlersTest extends IncomeHandlerTestCase
{
    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_adds_a_method_at_the_end_of_the_list_for_an_owner_or_manager(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->add($this->user($actor), $id, '  BLIK ');

        $method = $this->paymentMethods->findById(Uuid::fromString($id)) ?? self::fail('Method expected.');
        self::assertSame('BLIK', $method->getName());
        self::assertSame(4, $method->getPosition());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $method->getCreatedAt());
        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher', 'BLIK'], $this->names($this->list($this->owner)));
    }

    public function test_it_refuses_a_name_another_method_has_even_deactivated(): void
    {
        $this->deactivate($this->owner, $this->voucher);

        foreach (['Cash', ' Cash ', 'Voucher'] as $name) {
            try {
                $this->add($this->owner, Uuid::v7()->toRfc4122(), $name);
                self::fail(\sprintf('"%s" should be taken.', $name));
            } catch (PaymentMethodNameTakenException) {
            }
        }

        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'cash');
        self::assertCount(5, $this->paymentMethods->findAll(includeDeactivated: true));
    }

    public function test_it_renames_a_method_active_or_not(): void
    {
        $this->rename($this->manager, $this->card->getId()->toRfc4122(), 'Card (terminal)');
        $this->deactivate($this->owner, $this->voucher);
        $this->rename($this->owner, $this->voucher->getId()->toRfc4122(), 'Gift card');

        self::assertSame(['Card (terminal)', 'Gift card'], [$this->card->getName(), $this->voucher->getName()]);

        $this->expectException(PaymentMethodNameTakenException::class);
        $this->rename($this->owner, $this->cash->getId()->toRfc4122(), 'Gift card');
    }

    public function test_it_keeps_at_most_one_active_method_marked_as_cash(): void
    {
        self::assertSame([true, false], [$this->get($this->owner, $this->cash->getId()->toRfc4122())?->cash, $this->get($this->owner, $this->card->getId()->toRfc4122())?->cash]);

        foreach ([
            fn () => $this->add($this->manager, Uuid::v7()->toRfc4122(), 'BLIK', cash: true),
            fn () => $this->update($this->owner, $this->card->getId()->toRfc4122(), 'Card', true),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A second active method was marked as cash.');
            } catch (CashPaymentMethodTakenException) {
            }
        }
        self::assertCount(4, $this->paymentMethods->findAll());

        // Moving the mark: remove it from the cash method first, then mark the other one (renamed in the same update).
        $this->update($this->manager, $this->cash->getId()->toRfc4122(), 'Cash', false);
        $this->update($this->manager, $this->card->getId()->toRfc4122(), 'Card (cash)', true);
        self::assertSame([false, true, 'Card (cash)'], [$this->cash->isCash(), $this->card->isCash(), $this->card->getName()]);

        // A deactivated cash method keeps its mark and is not reactivated while another active method is cash.
        $this->deactivate($this->owner, $this->card);
        $this->add($this->owner, $id = Uuid::v7()->toRfc4122(), 'Gotówka', cash: true);
        try {
            $this->reactivate($this->owner, $this->card);
            self::fail('A second active cash method was reactivated.');
        } catch (CashPaymentMethodTakenException $e) {
            self::assertSame(['paymentMethodId' => $this->card->getId()->toRfc4122(), 'name' => 'Card (cash)'], $e->errorParameters());
        }
        self::assertSame([false, true], [$this->card->isActive(), $this->paymentMethods->findActiveCash()?->getId()->toRfc4122() === $id]);
    }

    public function test_it_deactivates_and_reactivates_a_method_at_the_end_of_the_list(): void
    {
        $this->deactivate($this->manager, $this->card);

        self::assertFalse($this->card->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->card->getDeactivatedAt());
        self::assertSame(['Cash', 'Transfer', 'Voucher'], $this->names($this->list($this->owner)));
        self::assertSame(['Cash', 'Transfer', 'Voucher', 'Card'], $this->names($this->list($this->owner, includeDeactivated: true)));

        $this->reactivate($this->owner, $this->card);

        self::assertTrue($this->card->isActive());
        self::assertSame(['Cash', 'Transfer', 'Voucher', 'Card'], $this->names($this->list($this->owner)));
    }

    public function test_it_deletes_an_unused_method_only(): void
    {
        $this->recorded('2026-11-10');

        $this->delete($this->manager, $this->transfer->getId()->toRfc4122());
        self::assertNull($this->paymentMethods->findById($this->transfer->getId()));

        try {
            $this->delete($this->owner, $this->cash->getId()->toRfc4122());
            self::fail('A used method was deleted.');
        } catch (PaymentMethodInUseException) {
        }
        self::assertNotNull($this->paymentMethods->findById($this->cash->getId()));
    }

    public function test_it_tells_which_methods_are_used(): void
    {
        $this->recorded('2026-11-10');

        $views = $this->list($this->owner) ?? [];
        self::assertSame([true, false, false, false], array_map(static fn (PaymentMethodView $view): bool => $view->used, $views));
        self::assertEquals(PaymentMethodView::fromPaymentMethod($this->cash, true), $this->get($this->manager, $this->cash->getId()->toRfc4122()));
        self::assertFalse($this->get($this->manager, $this->card->getId()->toRfc4122())?->used);
    }

    public function test_it_puts_the_active_methods_in_a_new_order_in_one_save(): void
    {
        $this->deactivate($this->owner, $this->transfer);

        $this->reorder($this->manager, [$this->voucher, $this->cash, $this->card]);

        self::assertSame(['Voucher', 'Cash', 'Card', 'Transfer'], $this->names($this->list($this->owner, includeDeactivated: true)));
        self::assertSame(1, $this->paymentMethods->saveAllCallCount);
    }

    public function test_it_refuses_an_order_without_every_active_method(): void
    {
        $this->expectException(PaymentMethodOrderMismatchException::class);

        $this->reorder($this->owner, [$this->voucher, $this->cash, $this->card]);
    }

    /** @return iterable<string, array{\Closure(self, string): mixed}> */
    public static function writesById(): iterable
    {
        yield 'rename' => [static fn (self $test, string $id) => $test->rename($test->owner, $id, 'BLIK')];
        yield 'deactivate' => [static fn (self $test, string $id) => (new DeactivatePaymentMethodHandler($test->paymentMethods, $test->queryBus, $test->clock))(new DeactivatePaymentMethodCommand($test->organizationId(), $test->owner->getId()->toRfc4122(), $id))];
        yield 'reactivate' => [static fn (self $test, string $id) => (new ReactivatePaymentMethodHandler($test->paymentMethods, $test->queryBus))(new ReactivatePaymentMethodCommand($test->organizationId(), $test->owner->getId()->toRfc4122(), $id))];
        yield 'delete' => [static fn (self $test, string $id) => $test->delete($test->owner, $id)];
    }

    /** @param \Closure(self, string): mixed $write */
    #[DataProvider('writesById')]
    public function test_it_reports_a_method_the_organization_does_not_have(\Closure $write): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'cash'] as $id) {
            try {
                $write($this, $id);
                self::fail('The method was found.');
            } catch (PaymentMethodNotFoundException) {
            }
        }
        self::assertNull($this->get($this->owner, 'cash'));
        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
    }

    public function test_it_lets_an_employee_neither_read_nor_change_methods(): void
    {
        foreach ([
            fn () => $this->list($this->employee),
            fn () => $this->get($this->employee, $this->cash->getId()->toRfc4122()),
            fn () => $this->add($this->employee, Uuid::v7()->toRfc4122(), 'BLIK'),
            fn () => $this->rename($this->employee, $this->cash->getId()->toRfc4122(), 'BLIK'),
            fn () => $this->deactivate($this->employee, $this->cash),
            fn () => $this->delete($this->employee, $this->card->getId()->toRfc4122()),
            fn () => $this->reorder($this->employee, [$this->card, $this->cash, $this->transfer, $this->voucher]),
        ] as $action) {
            try {
                $action();
                self::fail('An employee was let through.');
            } catch (IncomeManagementForbiddenException) {
            }
        }
        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher'], $this->names($this->list($this->owner)));
    }

    public function test_it_treats_a_deactivated_member_or_a_stranger_as_not_a_member(): void
    {
        $former = UserMother::any('dora@example.com');
        OrganizationMother::addMember($this->organization, $former, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $former, new \DateTimeImmutable('2026-11-01'));

        self::assertNull($this->list($former));
        self::assertNull($this->get(UserMother::any('stranger@example.com'), $this->cash->getId()->toRfc4122()));

        $this->expectException(OrganizationNotFoundException::class);
        $this->add($former, Uuid::v7()->toRfc4122(), 'BLIK');
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function add(User $actor, string $id, string $name, bool $cash = false): void
    {
        (new AddPaymentMethodHandler($this->paymentMethods, $this->queryBus, $this->clock))(
            new AddPaymentMethodCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name, $cash),
        );
    }

    private function rename(User $actor, string $id, string $name): void
    {
        $method = Uuid::isValid($id) ? $this->paymentMethods->findById(Uuid::fromString($id)) : null;
        $this->update($actor, $id, $name, $method?->isCash() ?? false);
    }

    private function update(User $actor, string $id, string $name, bool $cash): void
    {
        (new UpdatePaymentMethodHandler($this->paymentMethods, $this->queryBus))(
            new UpdatePaymentMethodCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name, $cash),
        );
    }

    private function reactivate(User $actor, PaymentMethod $method): void
    {
        (new ReactivatePaymentMethodHandler($this->paymentMethods, $this->queryBus))(
            new ReactivatePaymentMethodCommand($this->organizationId(), $actor->getId()->toRfc4122(), $method->getId()->toRfc4122()),
        );
    }

    private function deactivate(User $actor, PaymentMethod $method): void
    {
        (new DeactivatePaymentMethodHandler($this->paymentMethods, $this->queryBus, $this->clock))(
            new DeactivatePaymentMethodCommand($this->organizationId(), $actor->getId()->toRfc4122(), $method->getId()->toRfc4122()),
        );
    }

    private function delete(User $actor, string $id): void
    {
        (new DeletePaymentMethodHandler($this->paymentMethods, $this->entries, $this->queryBus))(
            new DeletePaymentMethodCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @param list<PaymentMethod> $methods */
    private function reorder(User $actor, array $methods): void
    {
        (new ReorderPaymentMethodsHandler($this->paymentMethods, $this->queryBus))(
            new ReorderPaymentMethodsCommand($this->organizationId(), $actor->getId()->toRfc4122(), array_map(static fn (PaymentMethod $method): string => $method->getId()->toRfc4122(), $methods)),
        );
    }

    private function get(User $actor, string $id): ?PaymentMethodView
    {
        return (new GetPaymentMethodHandler($this->paymentMethods, $this->entries, $this->queryBus))(
            new GetPaymentMethodQuery($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<PaymentMethodView>|null */
    private function list(User $actor, bool $includeDeactivated = false): ?array
    {
        return (new ListPaymentMethodsHandler($this->paymentMethods, $this->entries, $this->queryBus))(
            new ListPaymentMethodsQuery($this->organizationId(), $actor->getId()->toRfc4122(), $includeDeactivated),
        );
    }

    /**
     * @param list<PaymentMethodView>|null $views
     *
     * @return list<string>
     */
    private function names(?array $views): array
    {
        return array_map(static fn (PaymentMethodView $view): string => $view->name, $views ?? []);
    }
}
