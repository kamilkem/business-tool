<?php

declare(strict_types=1);

namespace App\Tests\Functional\Income;

use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\PaymentMethodOrder;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Income\Infrastructure\Doctrine\Orm\Repository\PaymentMethodRepository;
use App\Income\Infrastructure\Doctrine\Orm\Repository\RevenueEntryRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * PaymentMethodRepository against PostgreSQL: the order, the unique name per organization, the
 * foreign key that keeps a used method, and the TenantFilter; and which methods entries use.
 */
#[CoversClass(PaymentMethodRepository::class)]
#[CoversClass(RevenueEntryRepository::class)]
final class PaymentMethodRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private PaymentMethodRepositoryInterface $methods;
    private RevenueEntryRepositoryInterface $entries;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->methods = self::getContainer()->get(PaymentMethodRepositoryInterface::class);
        $this->entries = self::getContainer()->get(RevenueEntryRepositoryInterface::class);
    }

    public function test_it_lists_active_methods_by_position_and_the_deactivated_ones_last_on_request(): void
    {
        $this->switchToNewOrganization();
        self::assertSame(0, $this->methods->nextPosition());
        $cash = $this->add('Cash');
        $card = $this->add('Card');
        $old = $this->add('Old terminal');
        $blik = $this->add('BLIK');
        self::assertSame(4, $this->methods->nextPosition());

        $old->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
        $this->methods->save($old);
        PaymentMethodOrder::apply([$cash, $card, $blik], [$blik->getId()->toRfc4122(), $cash->getId()->toRfc4122(), $card->getId()->toRfc4122()], Uuid::v7(), MembershipRole::Owner);
        $this->methods->saveAll([$cash, $card, $blik]);
        $this->entityManager->clear();

        self::assertSame(['BLIK', 'Cash', 'Card'], self::names($this->methods->findAll()));
        self::assertSame(['BLIK', 'Cash', 'Card', 'Old terminal'], self::names($this->methods->findAll(includeDeactivated: true)));
        // After every position in use (0 to 2 now, the deactivated method kept its 2).
        self::assertSame(3, $this->methods->nextPosition());
        self::assertSame('Card', $this->methods->findByName('Card')?->getName());
        self::assertNull($this->methods->findByName('card'));
    }

    public function test_it_rejects_a_second_method_with_the_same_name_in_the_organization(): void
    {
        $this->switchToNewOrganization();
        $this->add('Cash');

        $this->expectException(PaymentMethodNameTakenException::class);

        $this->add('Cash');
    }

    public function test_it_finds_the_active_cash_method_and_keeps_deactivated_ones_out_of_the_rule(): void
    {
        $this->switchToNewOrganization();
        self::assertNull($this->methods->findActiveCash());
        $old = $this->add('Old cash', cash: true);
        $old->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
        $this->methods->save($old);
        self::assertNull($this->methods->findActiveCash());

        $cash = $this->add('Gotówka', cash: true);
        $this->entityManager->clear();

        $found = $this->methods->findActiveCash();
        self::assertInstanceOf(PaymentMethod::class, $found);
        self::assertSame($cash->getId()->toRfc4122(), $found->getId()->toRfc4122());
        $this->switchToNewOrganization();
        self::assertNull($this->methods->findActiveCash());
        $this->add('Gotówka', cash: true);
    }

    public function test_it_rejects_a_second_active_cash_method_written_concurrently(): void
    {
        $this->switchToNewOrganization();
        $this->add('Gotówka', cash: true);

        // The model was given no active cash method, as in a concurrent request; the partial unique index stops it.
        $this->expectException(CashPaymentMethodTakenException::class);

        $this->add('Cash', cash: true);
    }

    public function test_it_deletes_an_unused_method_and_keeps_a_used_one(): void
    {
        $this->switchToNewOrganization();
        $cash = $this->add('Cash');
        $card = $this->add('Card');
        $this->entries->add($this->record($this->addRegister(), [new PaymentAmount($cash, 100)]));

        self::assertTrue($this->entries->usesPaymentMethod($cash->getId()));
        self::assertFalse($this->entries->usesPaymentMethod($card->getId()));
        self::assertSame([$cash->getId()->toRfc4122()], $this->entries->usedPaymentMethodIds());

        $this->methods->remove($card);
        self::assertNull($this->methods->findById($card->getId()));

        // The foreign key holds when the check was skipped (an entry recorded in the meantime).
        $this->expectException(PaymentMethodInUseException::class);
        $this->methods->remove($cash);
    }

    public function test_it_does_not_show_another_organizations_methods_or_their_use(): void
    {
        $this->switchToNewOrganization();
        $foreign = $this->add('Cash');
        $this->entries->add($this->record($this->addRegister(), [new PaymentAmount($foreign, 100)]));
        $this->entityManager->clear();

        $this->switchToNewOrganization();

        self::assertNull($this->methods->findById($foreign->getId()));
        self::assertNull($this->methods->findByName('Cash'));
        self::assertSame([], $this->methods->findAll(includeDeactivated: true));
        self::assertSame(0, $this->methods->nextPosition());
        self::assertFalse($this->entries->usesPaymentMethod($foreign->getId()));
        self::assertSame([], $this->entries->usedPaymentMethodIds());
        $this->add('Cash');
    }

    private function switchToNewOrganization(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
    }

    private function add(string $name, bool $cash = false): PaymentMethod
    {
        $method = PaymentMethod::create(Uuid::v7(), $name, null, $this->methods->nextPosition(), $cash, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
        $this->methods->add($method);

        return $method;
    }

    private function addRegister(): Register
    {
        $register = Register::create(Uuid::v7(), 'Bar', null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
        self::getContainer()->get(RegisterRepositoryInterface::class)->add($register);

        return $register;
    }

    /** @param list<PaymentAmount> $payments */
    private function record(Register $register, array $payments): RevenueEntry
    {
        return RevenueEntry::record(Uuid::v7(), $register, BusinessDate::fromString('2026-11-11'), 'PLN', 'PL', $payments, [], null, Uuid::v7(), MembershipRole::Owner, BusinessDate::fromString('2026-11-30'), new \DateTimeImmutable('2026-11-12 10:00:00'));
    }

    /**
     * @param list<PaymentMethod> $methods
     *
     * @return list<string>
     */
    private static function names(array $methods): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->getName(), $methods);
    }
}
