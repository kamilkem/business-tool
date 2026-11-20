<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Payroll\Domain\Exception\InvalidPayRateException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayRateDateTakenException;
use App\Payroll\Domain\Exception\PayRatePeriodLockedException;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A person's hourly rate for one business date (New Year's Eve), replacing their hourly rate for the
 * shifts that started on that date (ADR 0027 §3, §5). One per person and date (409). The amount, the
 * currency, the access rules and deactivated members are as for HourlyRate; a change is refused when
 * the old or the new date is in a locked period.
 */
#[ORM\Entity]
#[ORM\Table(name: 'day_rate')]
#[ORM\UniqueConstraint(name: self::ONE_PER_USER_AND_DATE, columns: ['organization_id', 'user_id', 'business_date'])]
class DayRate implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, user, date); the repository adapter recognizes its violations. */
    public const string ONE_PER_USER_AND_DATE = 'day_rate_one_per_user_and_date';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The member (App\User) the rate is for. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(name: 'business_date', type: 'business_date')]
    private BusinessDate $date;

    /** Gross minor units per hour. */
    #[ORM\Column]
    private int $amount;

    /** ISO 4217, the organization's when the rate was set or last changed. */
    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    private function __construct(Uuid $id, Uuid $userId, BusinessDate $date, int $amount, string $currency, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->date = $date;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?DayRate $sameDate the person's day rate on that date, if any
     *                           (DayRateRepositoryInterface::findByUserAndDate())
     * @param string   $currency the organization's (ISO 4217)
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when the person has a day rate on that date
     * @throws PayRatePeriodLockedException    when the date is locked
     */
    public static function set(
        Uuid $id,
        Uuid $userId,
        BusinessDate $date,
        int $amount,
        string $currency,
        ?self $sameDate,
        LockedPayrollDates $locks,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): self {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        HourlyRate::assertAmount($amount);
        if (null !== $sameDate) {
            throw PayRateDateTakenException::dayRate($userId->toRfc4122(), $date);
        }
        $locks->assertUnlocked($date, $date);

        return new self($id, $userId, $date, $amount, $currency, $now);
    }

    /**
     * Changes the date, the amount or both; the currency becomes the organization's. Changing
     * nothing is allowed even when the date is locked.
     *
     * @param ?DayRate $sameDate the person's day rate on the new date, if any
     * @param string   $currency the organization's (ISO 4217)
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when another day rate of the person is on the new date
     * @throws PayRatePeriodLockedException    when the old or the new date is locked
     */
    public function change(
        BusinessDate $date,
        int $amount,
        string $currency,
        ?self $sameDate,
        LockedPayrollDates $locks,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): void {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        HourlyRate::assertAmount($amount);
        if ($date->equals($this->date) && $amount === $this->amount && $currency === $this->currency) {
            return;
        }

        if (null !== $sameDate && !$sameDate->id->equals($this->id)) {
            throw PayRateDateTakenException::dayRate($this->userId->toRfc4122(), $date);
        }
        $locks->assertUnlocked($this->date, $this->date);
        $locks->assertUnlocked($date, $date);

        $this->date = $date;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->updatedAt = $now;
    }

    /**
     * Checks that the day rate may be deleted; the handler then removes it. The date falls back to
     * the person's hourly rate.
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRatePeriodLockedException    when the date is locked
     */
    public function delete(LockedPayrollDates $locks, Uuid $actorId, MembershipRole $actorRole): void
    {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        $locks->assertUnlocked($this->date, $this->date);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDate(): BusinessDate
    {
        return $this->date;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
