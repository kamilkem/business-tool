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
 * A person's usual hourly rate from a business date until the day before their next rate (ADR 0027
 * §3). A raise is a new rate from a date; earlier dates keep the old one.
 *
 * - One rate per person and date: checked against the history, and the unique constraint below stops
 *   two concurrent writes (409).
 * - The amount is gross minor units per hour (ADR 0010) in the organization's currency when the rate
 *   is set or changed. A rate in another currency (the organization changed it) does not count for a
 *   run in the new one; that is the payroll run's rule (#62).
 * - A change is refused when any date it affects is in a locked period (LockedPayrollDates): the dates
 *   the rate covers before the change and the ones it covers after.
 * - Members are deactivated, never deleted (ADR 0014): a deactivated member keeps their rates and can
 *   get new ones, since their shifts still count.
 * - Owners and managers only (ADR 0027 §10).
 */
#[ORM\Entity]
#[ORM\Table(name: 'hourly_rate')]
#[ORM\UniqueConstraint(name: self::ONE_PER_USER_AND_DATE, columns: ['organization_id', 'user_id', 'effective_from'])]
class HourlyRate implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, user, date); the repository adapter recognizes its violations. */
    public const string ONE_PER_USER_AND_DATE = 'hourly_rate_one_per_user_and_date';

    /** The largest rate in minor units: the largest PostgreSQL INTEGER. */
    public const int MAX_AMOUNT = 2_147_483_647;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The member (App\User) the rate is for. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $effectiveFrom;

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

    private function __construct(Uuid $id, Uuid $userId, BusinessDate $effectiveFrom, int $amount, string $currency, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->effectiveFrom = $effectiveFrom;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->createdAt = $createdAt;
    }

    /**
     * @param HourlyRateHistory $history  the person's rates
     * @param string            $currency the organization's (ISO 4217)
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when the person has a rate from that date
     * @throws PayRatePeriodLockedException    when a date the rate would be in effect on is locked
     */
    public static function set(
        Uuid $id,
        HourlyRateHistory $history,
        BusinessDate $effectiveFrom,
        int $amount,
        string $currency,
        LockedPayrollDates $locks,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): self {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        self::assertAmount($amount);
        if (null !== $history->startingOn($effectiveFrom)) {
            throw PayRateDateTakenException::hourlyRate($history->userId->toRfc4122(), $effectiveFrom);
        }
        $locks->assertUnlocked($effectiveFrom, $history->lastDateFrom($effectiveFrom));

        return new self($id, $history->userId, $effectiveFrom, $amount, $currency, $now);
    }

    /**
     * Changes the date, the amount or both; the currency becomes the organization's. Changing
     * nothing is allowed even when the dates are locked.
     *
     * @param HourlyRateHistory $history  the person's rates, this one included
     * @param string            $currency the organization's (ISO 4217)
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidPayRateException         when the amount is not positive or too large
     * @throws PayRateDateTakenException       when another rate of the person is from the new date
     * @throws PayRatePeriodLockedException    when a date the rate is or would be in effect on is locked
     */
    public function change(
        HourlyRateHistory $history,
        BusinessDate $effectiveFrom,
        int $amount,
        string $currency,
        LockedPayrollDates $locks,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): void {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        self::assertAmount($amount);
        $this->assertHistoryIsMine($history);
        if ($effectiveFrom->equals($this->effectiveFrom) && $amount === $this->amount && $currency === $this->currency) {
            return;
        }

        $sameDate = $history->startingOn($effectiveFrom);
        if (null !== $sameDate && !$sameDate->id->equals($this->id)) {
            throw PayRateDateTakenException::hourlyRate($this->userId->toRfc4122(), $effectiveFrom);
        }
        $locks->assertUnlocked($this->effectiveFrom, $history->lastDateFrom($this->effectiveFrom, $this));
        $locks->assertUnlocked($effectiveFrom, $history->lastDateFrom($effectiveFrom, $this));

        $this->effectiveFrom = $effectiveFrom;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->updatedAt = $now;
    }

    /**
     * Checks that the rate may be deleted; the handler then removes it. Its dates fall back to the
     * person's previous rate, or to none.
     *
     * @param HourlyRateHistory $history the person's rates, this one included
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayRatePeriodLockedException    when a date the rate is in effect on is locked
     */
    public function delete(HourlyRateHistory $history, LockedPayrollDates $locks, Uuid $actorId, MembershipRole $actorRole): void
    {
        PayrollAccess::assertCanManage($actorId, $actorRole);
        $this->assertHistoryIsMine($history);
        $locks->assertUnlocked($this->effectiveFrom, $history->lastDateFrom($this->effectiveFrom, $this));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getEffectiveFrom(): BusinessDate
    {
        return $this->effectiveFrom;
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

    /** @throws InvalidPayRateException */
    public static function assertAmount(int $amount): void
    {
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw InvalidPayRateException::amount($amount, self::MAX_AMOUNT);
        }
    }

    private function assertHistoryIsMine(HourlyRateHistory $history): void
    {
        if (!$history->userId->equals($this->userId)) {
            throw new \LogicException(\sprintf('The history of user "%s" is not the one of hourly rate "%s".', $history->userId->toRfc4122(), $this->id->toRfc4122()));
        }
    }
}
