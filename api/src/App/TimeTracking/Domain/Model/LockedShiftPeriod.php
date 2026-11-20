<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\InvalidBusinessPeriodException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Business dates whose shifts no longer change, because something settled them: an approved payroll
 * run locks its month (ADR 0027 §8). Time tracking knows only the id of what locked the period
 * (lockedBy, the run's id), never what it is; one lock per locker and organization, so locking and
 * unlocking for the same id again change nothing.
 *
 * A shift is in the period when the business date it started on, in the organization's time zone,
 * is (ADR 0027 §5): ShiftPeriodLocks checks it for every shift write.
 */
#[ORM\Entity]
#[ORM\Table(name: 'locked_shift_period')]
#[ORM\UniqueConstraint(name: self::ONE_PER_LOCKER, columns: ['organization_id', 'locked_by'])]
class LockedShiftPeriod implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, locked by). */
    public const string ONE_PER_LOCKER = 'locked_shift_period_one_per_locker';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The id of what locked the period (a payroll run). */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $lockedBy;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $firstDate;

    #[ORM\Column(type: 'business_date')]
    private BusinessDate $lastDate;

    #[ORM\Column]
    private \DateTimeImmutable $lockedAt;

    /** @throws InvalidBusinessPeriodException when the last date is before the first */
    public function __construct(Uuid $id, Uuid $lockedBy, BusinessPeriod $period, \DateTimeImmutable $lockedAt)
    {
        // A BusinessPeriod is valid by construction; checked again so that the entity never holds a reversed one.
        $period = BusinessPeriod::fromDates($period->first, $period->last);

        $this->id = $id;
        $this->lockedBy = $lockedBy;
        $this->firstDate = $period->first;
        $this->lastDate = $period->last;
        $this->lockedAt = $lockedAt;
    }

    public function contains(BusinessDate $date): bool
    {
        return $this->getPeriod()->contains($date);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLockedBy(): Uuid
    {
        return $this->lockedBy;
    }

    public function getPeriod(): BusinessPeriod
    {
        return BusinessPeriod::fromDates($this->firstDate, $this->lastDate);
    }

    public function getLockedAt(): \DateTimeImmutable
    {
        return $this->lockedAt;
    }
}
