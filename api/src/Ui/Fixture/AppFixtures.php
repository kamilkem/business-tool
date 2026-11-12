<?php

declare(strict_types=1);

namespace Ui\Fixture;

use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\User\Domain\Model\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Local development data. All demo accounts use the password "password".
 *
 *   owner@example.com     owner of "Demo Bar"
 *   manager@example.com   manager of "Demo Bar"
 *   employee@example.com  employee of "Demo Bar"
 *   mixed@example.com     owner of "Demo Café" and employee of "Demo Bar" (the admin follows the selected organization's role)
 *
 * "Demo Bar" has the registers "Bar" and "Kitchen" with an entry for each of the last 31 days
 * (the kitchen is closed on Mondays), and a deactivated register "Terrace".
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_PASSWORD = 'password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $owner = $this->user($manager, 'owner@example.com', 'Olivia', 'Owner', $now);
        $bar = new Organization(Uuid::v7(), 'Demo Bar', $owner, $now);
        $bar->addMember($owner->getId(), $this->user($manager, 'manager@example.com', 'Max', 'Manager', $now), MembershipRole::Manager, $now);
        $bar->addMember($owner->getId(), $this->user($manager, 'employee@example.com', 'Eve', 'Employee', $now), MembershipRole::Employee, $now);
        $manager->persist($bar);

        $mixed = $this->user($manager, 'mixed@example.com', 'Mia', 'Mixed', $now);
        $bar->addMember($owner->getId(), $mixed, MembershipRole::Employee, $now);
        $manager->persist(new Organization(Uuid::v7(), 'Demo Café', $mixed, $now));

        $manager->flush();

        $this->income($manager, $bar, $owner->getId(), $now);
        $manager->flush();
    }

    /**
     * Registers and a month of daily reports, as an owner enters them (ADR 0022). The amounts are
     * made up but repeatable; the bar's VAT breakdown leaves out transfers (invoiced events are not
     * on the fiscal report), and a few days have no breakdown yet.
     */
    private function income(ObjectManager $manager, Organization $organization, Uuid $ownerId, \DateTimeImmutable $now): void
    {
        $this->tenantContext->switchTo($organization);
        $registers = [];
        foreach (['Bar', 'Kitchen', 'Terrace'] as $name) {
            $registers[$name] = Register::create(Uuid::v7(), $name, null, $ownerId, MembershipRole::Owner, $now);
            $manager->persist($registers[$name]);
        }
        $registers['Terrace']->deactivate($ownerId, MembershipRole::Owner, $now);

        $today = BusinessDate::containing($now, new \DateTimeZone($organization->getTimezone()));
        mt_srand(41);
        $date = $today;
        for ($day = 1; $day <= 31; ++$day) {
            $date = $date->previous();
            $isMonday = 1 === (int) new \DateTimeImmutable($date->toString())->format('N');
            $isWeekend = (int) new \DateTimeImmutable($date->toString())->format('N') >= 5;
            $scale = $isWeekend ? 2 : 1;

            $cash = mt_rand(800, 2500) * 100 * $scale;
            $card = mt_rand(2000, 6000) * 100 * $scale;
            $transfer = 0 === $day % 9 ? mt_rand(5, 20) * 10000 : 0;
            $voucher = 0 === $day % 4 ? mt_rand(1, 3) * 5000 : 0;
            $payments = [new PaymentAmount(PaymentMethod::Cash, $cash), new PaymentAmount(PaymentMethod::Card, $card)];
            if ($transfer > 0) {
                $payments[] = new PaymentAmount(PaymentMethod::Transfer, $transfer);
            }
            if ($voucher > 0) {
                $payments[] = new PaymentAmount(PaymentMethod::Voucher, $voucher);
            }
            $fiscal = $cash + $card + $voucher;
            $vat = 0 === $day % 10 ? [] : [
                self::vatLine('23', (int) round($fiscal * 0.7), 23),
                self::vatLine('8', (int) round($fiscal * 0.25), 8),
                self::vatLine('5', $fiscal - (int) round($fiscal * 0.7) - (int) round($fiscal * 0.25), 5),
            ];
            $manager->persist($this->entry($organization, $registers['Bar'], $date, $payments, $vat, 0 === $transfer ? null : 'Invoiced event paid by transfer', $ownerId, $today, $now));

            if (!$isMonday) {
                $kitchenCash = mt_rand(300, 1200) * 100 * $scale;
                $kitchenCard = mt_rand(800, 2500) * 100 * $scale;
                $kitchen = [new PaymentAmount(PaymentMethod::Cash, $kitchenCash), new PaymentAmount(PaymentMethod::Card, $kitchenCard)];
                $manager->persist($this->entry($organization, $registers['Kitchen'], $date, $kitchen, [self::vatLine('8', $kitchenCash + $kitchenCard, 8)], null, $ownerId, $today, $now));
            }
        }
    }

    /**
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     */
    private function entry(Organization $organization, Register $register, BusinessDate $date, array $payments, array $vat, ?string $note, Uuid $ownerId, BusinessDate $today, \DateTimeImmutable $now): RevenueEntry
    {
        return RevenueEntry::record(
            Uuid::v7(),
            $register,
            $date,
            $organization->getCurrency(),
            $organization->getCountryCode(),
            $payments,
            $vat,
            $note,
            $ownerId,
            MembershipRole::Owner,
            $today,
            $now,
        );
    }

    /** VAT in a gross amount at a percentage rate, rounded as a fiscal printer would. */
    private static function vatLine(string $rate, int $gross, int $percent): VatAmount
    {
        return new VatAmount($rate, $gross, (int) round($gross * $percent / (100 + $percent)));
    }

    private function user(ObjectManager $manager, string $email, string $firstName, string $lastName, \DateTimeImmutable $now): User
    {
        $user = new User(Uuid::v7(), $email, $now, $firstName, $lastName);
        $user->changePassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        $manager->persist($user);

        return $user;
    }
}
