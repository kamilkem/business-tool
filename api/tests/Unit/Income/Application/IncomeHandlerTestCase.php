<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Query\GetRevenueEntry\RevenuePaymentLineView;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PaymentMethodMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization in Poland (Europe/Warsaw, PLN) with an owner, a manager, an employee, a register
 * "Bar" and the payment methods Cash, Card, Transfer and Voucher; the Organization module answers through its real queries over an in-memory repository.
 * NOW is late evening UTC, already the next business date in Warsaw.
 */
abstract class IncomeHandlerTestCase extends TestCase
{
    protected const string NOW = '2026-11-12 23:30:00';
    protected const string TODAY_IN_WARSAW = '2026-11-13';

    protected MockClock $clock;
    protected InMemoryOrganizationRepository $organizations;
    protected InMemoryRegisterRepository $registers;
    protected InMemoryRevenueEntryRepository $entries;
    protected InMemoryPaymentMethodRepository $paymentMethods;
    protected PaymentMethod $cash;
    protected PaymentMethod $card;
    protected PaymentMethod $transfer;
    protected PaymentMethod $voucher;
    protected StubQueryBus $queryBus;
    protected Organization $organization;
    protected User $owner;
    protected User $manager;
    protected User $employee;
    protected Register $bar;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->organizations = new InMemoryOrganizationRepository();
        $this->registers = new InMemoryRegisterRepository();
        $this->entries = new InMemoryRevenueEntryRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $this->organizations->add($this->organization);
        $this->bar = Register::create(Uuid::v7(), 'Bar', null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->registers->add($this->bar);
        $this->paymentMethods = new InMemoryPaymentMethodRepository();
        foreach (['cash' => 'Cash', 'card' => 'Card', 'transfer' => 'Transfer', 'voucher' => 'Voucher'] as $property => $name) {
            $this->{$property} = PaymentMethodMother::named($name, $this->paymentMethods->nextPosition(), cash: 'cash' === $property);
            $this->paymentMethods->add($this->{$property});
        }

        $organizations = $this->organizations;
        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    protected function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    /**
     * A payment line as commands carry it.
     *
     * @return array{paymentMethodId: string, amount: int}
     */
    protected static function line(PaymentMethod $method, int $amount): array
    {
        return ['paymentMethodId' => $method->getId()->toRfc4122(), 'amount' => $amount];
    }

    protected static function lineView(PaymentMethod $method, int $amount): RevenuePaymentLineView
    {
        return new RevenuePaymentLineView($method->getId()->toRfc4122(), $method->getName(), $amount);
    }

    /** An entry of the register "Bar" recorded by the owner. */
    protected function recorded(string $businessDate, int $cash = 100_000): RevenueEntry
    {
        $entry = RevenueEntry::record(
            Uuid::v7(),
            $this->bar,
            BusinessDate::fromString($businessDate),
            'PLN',
            'PL',
            [new PaymentAmount($this->cash, $cash)],
            [new VatAmount('23', $cash, (int) round($cash * 23 / 123))],
            null,
            $this->owner->getId(),
            MembershipRole::Owner,
            BusinessDate::fromString(self::TODAY_IN_WARSAW),
            new \DateTimeImmutable('2026-11-12 08:00:00'),
        );
        $this->entries->add($entry);

        return $entry;
    }
}
