<?php

declare(strict_types=1);

namespace App\Tests\Functional\Income;

use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\RevenuePaymentLine;
use App\Income\Domain\Model\RevenueVatLine;
use App\Income\Domain\Model\VatAmount;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Income\Infrastructure\Doctrine\Orm\Repository\RevenueEntryRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * RevenueEntryRepository against PostgreSQL: the business date as a DATE column, the lines, the
 * unique (organization, register, business date) and the TenantFilter on entries and lines.
 */
#[CoversClass(RevenueEntryRepository::class)]
#[CoversClass(BusinessDateType::class)]
final class RevenueEntryRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string TODAY = '2026-11-12';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private RegisterRepositoryInterface $registers;
    private RevenueEntryRepositoryInterface $entries;
    /** @var array<string, PaymentMethod> the current organization's, by name */
    private array $methods = [];

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->registers = self::getContainer()->get(RegisterRepositoryInterface::class);
        $this->entries = self::getContainer()->get(RevenueEntryRepositoryInterface::class);
    }

    public function test_it_stores_an_entry_with_its_lines_in_the_current_organization(): void
    {
        $organization = $this->switchToNewOrganization();
        $register = $this->addRegister('Bar');
        $entry = $this->record($register, '2026-11-11', [$this->card(250_000), $this->cash(120_050)], [new VatAmount('23', 270_050, 50_497), new VatAmount('zw', 1_000, 0)], 'Busy');
        $this->entries->add($entry);
        $this->entityManager->clear();

        $found = $this->entries->findById($entry->getId());
        self::assertNotNull($found);
        self::assertSame('2026-11-11', $found->getBusinessDate()->toString());
        self::assertSame('PLN', $found->getCurrency());
        self::assertSame('Bar', $found->getRegister()->getName());
        self::assertSame([['Cash', 120_050], ['Card', 250_000]], self::lines($found));
        self::assertEquals([new VatAmount('23', 270_050, 50_497), new VatAmount('zw', 1_000, 0)], $found->getVatLines());
        self::assertSame(370_050, $found->getTotal());
        self::assertSame('Busy', $found->getNote());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertSame($entry->getId()->toRfc4122(), $this->entries->findByRegisterAndDate($register->getId(), BusinessDate::fromString('2026-11-11'))?->getId()->toRfc4122());
        self::assertNull($this->entries->findByRegisterAndDate($register->getId(), BusinessDate::fromString('2026-11-10')));
    }

    public function test_it_rejects_a_second_entry_for_the_same_register_and_business_date(): void
    {
        $this->switchToNewOrganization();
        $register = $this->addRegister('Bar');
        $this->entries->add($this->record($register, '2026-11-11', [$this->cash(100)]));

        $this->expectException(RevenueEntryAlreadyExistsException::class);

        // A concurrent write that did not see the first entry: only the constraint can stop it.
        $this->entries->add($this->record($register, '2026-11-11', [$this->cash(200)]));
    }

    public function test_it_allows_one_entry_per_register_on_the_same_date_and_per_date_on_the_same_register(): void
    {
        $this->switchToNewOrganization();
        $bar = $this->addRegister('Bar');
        $kitchen = $this->addRegister('Kitchen');

        $this->entries->add($this->record($bar, '2026-11-11', [$this->cash(100)]));
        $this->entries->add($this->record($kitchen, '2026-11-11', [$this->cash(200)]));
        $this->entries->add($this->record($bar, '2026-11-10', [$this->cash(300)]));
        $this->entityManager->clear();

        self::assertCount(3, $this->entries->findInPeriod(BusinessPeriod::month(2026, 11)));
    }

    public function test_it_saves_changed_lines_keeping_the_methods_and_rates_that_stay(): void
    {
        $this->switchToNewOrganization();
        $entry = $this->record($this->addRegister('Bar'), '2026-11-11', [$this->cash(100), $this->card(200)], [new VatAmount('23', 300, 56), new VatAmount('8', 50, 4)]);
        $this->entries->add($entry);

        // Card stays, cash goes and voucher comes; 23 stays, 8 goes and zw comes.
        $entry->change([$this->card(250), $this->voucher(50)], [new VatAmount('23', 250, 47), new VatAmount('zw', 10, 0)], null, 'PL', Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable('2026-11-12 09:00:00'));
        $this->entries->save($entry);
        $this->entityManager->clear();

        $found = $this->entries->findById($entry->getId());
        self::assertNotNull($found);
        self::assertSame([['Card', 250], ['Voucher', 50]], self::lines($found));
        self::assertEquals([new VatAmount('23', 250, 47), new VatAmount('zw', 10, 0)], $found->getVatLines());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 09:00:00'), $found->getUpdatedAt());
    }

    public function test_it_removes_an_entry_with_its_lines(): void
    {
        $this->switchToNewOrganization();
        $entry = $this->record($this->addRegister('Bar'), '2026-11-11', [$this->cash(100), $this->card(200)], [new VatAmount('23', 300, 56)]);
        $this->entries->add($entry);

        $this->entries->remove($entry);
        $this->entityManager->clear();

        self::assertNull($this->entries->findById($entry->getId()));
        self::assertSame(0, $this->countLines(RevenuePaymentLine::class));
        self::assertSame(0, $this->countLines(RevenueVatLine::class));
    }

    public function test_it_finds_the_entries_of_a_period_by_date_then_register_name(): void
    {
        $this->switchToNewOrganization();
        $kitchen = $this->addRegister('Kitchen');
        $bar = $this->addRegister('Bar');
        foreach ([[$kitchen, '2026-11-01'], [$bar, '2026-11-01'], [$bar, '2026-10-31'], [$bar, '2026-11-30'], [$kitchen, '2026-11-12'], [$bar, '2026-11-12']] as [$register, $date]) {
            $this->entries->add($this->record($register, $date, [$this->cash(100)]));
        }
        $this->entityManager->clear();

        $november = $this->entries->findInPeriod(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'));
        self::assertSame(
            ['2026-11-01 Bar', '2026-11-01 Kitchen', '2026-11-12 Bar', '2026-11-12 Kitchen', '2026-11-30 Bar'],
            array_map(static fn (RevenueEntry $e): string => $e->getBusinessDate()->toString().' '.$e->getRegister()->getName(), $november),
        );
        self::assertCount(2, $this->entries->findInPeriod(BusinessPeriod::month(2026, 11), $kitchen->getId()));
        self::assertCount(2, $this->entries->findInPeriod(BusinessPeriod::fromStrings('2026-10-31', '2026-11-01'), $bar->getId()));
        self::assertCount(1, $this->entries->findInPeriod(BusinessPeriod::fromStrings('2026-10-31', '2026-10-31')));
    }

    public function test_it_reads_each_line_of_a_periods_entries_once_although_both_kinds_are_fetch_joined(): void
    {
        $this->switchToNewOrganization();
        $bar = $this->addRegister('Bar');
        $kitchen = $this->addRegister('Kitchen');
        $this->entries->add($this->record($bar, '2026-11-01', [$this->cash(100), $this->card(200), $this->voucher(50)], [new VatAmount('23', 246, 46), new VatAmount('8', 54, 4)]));
        $this->entries->add($this->record($kitchen, '2026-11-01', [$this->card(70)]));
        $this->entityManager->clear();

        $entries = $this->entries->findInPeriod(BusinessPeriod::month(2026, 11));

        self::assertSame(
            [['Bar', 350, 3, 2], ['Kitchen', 70, 1, 0]],
            array_map(static fn (RevenueEntry $e): array => [$e->getRegister()->getName(), $e->getTotal(), \count($e->getPaymentLines()), \count($e->getVatLines())], $entries),
        );
    }

    public function test_it_does_not_show_another_organizations_entries_or_lines(): void
    {
        $this->switchToNewOrganization();
        $register = $this->addRegister('Bar');
        $foreign = $this->record($register, '2026-11-11', [$this->cash(100)], [new VatAmount('23', 100, 19)]);
        $this->entries->add($foreign);
        $this->entityManager->clear();

        $this->switchToNewOrganization();

        self::assertNull($this->entries->findById($foreign->getId()));
        self::assertNull($this->entries->findByRegisterAndDate($register->getId(), BusinessDate::fromString('2026-11-11')));
        self::assertSame([], $this->entries->findInPeriod(BusinessPeriod::month(2026, 11)));
        self::assertSame(0, $this->countLines(RevenuePaymentLine::class));
        self::assertSame(0, $this->countLines(RevenueVatLine::class));
    }

    private function switchToNewOrganization(): Organization
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $this->methods = [];

        return $organization;
    }

    private function addRegister(string $name): Register
    {
        $register = Register::create(Uuid::v7(), $name, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
        $this->registers->add($register);

        return $register;
    }

    /**
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     */
    private function record(Register $register, string $date, array $payments, array $vat = [], ?string $note = null): RevenueEntry
    {
        return RevenueEntry::record(
            Uuid::v7(),
            $register,
            BusinessDate::fromString($date),
            'PLN',
            'PL',
            $payments,
            $vat,
            $note,
            Uuid::v7(),
            MembershipRole::Owner,
            BusinessDate::fromString('2026-11-30'),
            new \DateTimeImmutable(self::TODAY.' 10:00:00'),
        );
    }

    /** Lines visible in the current organization, queried on their own (no join through an entry). */
    private function countLines(string $class): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(IDENTITY(l.revenueEntry)) FROM %s l', $class))->getSingleScalarResult();
    }

    /** @return list<array{string, int}> the lines as (method name, amount), in their order */
    private static function lines(RevenueEntry $entry): array
    {
        return array_map(static fn (PaymentAmount $line): array => [$line->method->getName(), $line->amount], $entry->getPaymentLines());
    }

    private function cash(int $amount): PaymentAmount
    {
        return new PaymentAmount($this->method('Cash', 0), $amount);
    }

    private function card(int $amount): PaymentAmount
    {
        return new PaymentAmount($this->method('Card', 1), $amount);
    }

    private function voucher(int $amount): PaymentAmount
    {
        return new PaymentAmount($this->method('Voucher', 3), $amount);
    }

    /** The current organization's method with the name, stored on first use. */
    private function method(string $name, int $position): PaymentMethod
    {
        if (!isset($this->methods[$name])) {
            $this->methods[$name] = PaymentMethod::create(Uuid::v7(), $name, null, $position, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
            self::getContainer()->get(PaymentMethodRepositoryInterface::class)->add($this->methods[$name]);
        }

        return $this->methods[$name];
    }
}
