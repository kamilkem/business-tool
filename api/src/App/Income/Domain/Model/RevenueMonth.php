<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Income\Domain\Exception\RevenueMonthNotClosedException;
use App\Income\Domain\Exception\RevenueMonthNotEndedException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A calendar month of Income in the organization's time zone, closed by an owner once it has ended,
 * which locks its revenue entries (ADR 0034). It exists from the first close on; a month without one
 * is open. Owners reopen and close it again any number of times, and every close and reopen stays in
 * its log of actions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_month')]
#[ORM\UniqueConstraint(name: self::ONE_PER_MONTH, columns: ['organization_id', 'month'])]
class RevenueMonth implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, month); the repository adapter recognizes its violations. */
    public const string ONE_PER_MONTH = 'revenue_month_one_per_month';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The month's first day, a DATE column (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $month;

    /** Who closed the month last; null while it is open. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $closedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, RevenueMonthAction> */
    #[ORM\OneToMany(targetEntity: RevenueMonthAction::class, mappedBy: 'month', cascade: ['persist'])]
    #[ORM\OrderBy(['at' => 'ASC', 'id' => 'ASC'])]
    private Collection $actions;

    private function __construct(Uuid $id, BusinessPeriod $month)
    {
        $this->id = $id;
        $this->month = $month->first;
        $this->actions = new ArrayCollection();
    }

    /**
     * Closes a month for the first time.
     *
     * @param BusinessDate $today today in the organization's time zone
     *
     * @throws RevenueMonthCloseForbiddenException when the actor is not an owner
     * @throws RevenueMonthNotEndedException       when the month has not ended
     */
    public static function closeFirst(Uuid $id, BusinessPeriod $month, Uuid $actorId, MembershipRole $actorRole, BusinessDate $today, \DateTimeImmutable $now): self
    {
        $revenueMonth = new self($id, BusinessPeriod::monthOf($month->first));
        $revenueMonth->close($actorId, $actorRole, $today, $now);

        return $revenueMonth;
    }

    /**
     * @param BusinessDate $today today in the organization's time zone
     *
     * @throws RevenueMonthCloseForbiddenException when the actor is not an owner
     * @throws RevenueMonthNotEndedException       when the month has not ended
     * @throws RevenueMonthAlreadyClosedException  when the month is closed
     */
    public function close(Uuid $actorId, MembershipRole $actorRole, BusinessDate $today, \DateTimeImmutable $now): void
    {
        IncomeAccess::assertCanCloseMonths($actorId, $actorRole);
        if ($this->isClosed()) {
            throw RevenueMonthAlreadyClosedException::of($this->monthString());
        }
        if (!$this->period()->last->isBefore($today)) {
            throw RevenueMonthNotEndedException::of($this->monthString(), $today->toString());
        }

        $this->closedBy = $actorId;
        $this->closedAt = $now;
        $this->actions->add(new RevenueMonthAction($this, RevenueMonthActionKind::Closed, $actorId, $now));
    }

    /**
     * @throws RevenueMonthCloseForbiddenException when the actor is not an owner
     * @throws RevenueMonthNotClosedException      when the month is open
     */
    public function reopen(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        IncomeAccess::assertCanCloseMonths($actorId, $actorRole);
        if (!$this->isClosed()) {
            throw RevenueMonthNotClosedException::of($this->monthString());
        }

        $this->closedBy = null;
        $this->closedAt = null;
        $this->actions->add(new RevenueMonthAction($this, RevenueMonthActionKind::Reopened, $actorId, $now));
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function period(): BusinessPeriod
    {
        return BusinessPeriod::monthOf($this->month);
    }

    /** YYYY-MM */
    public function monthString(): string
    {
        return substr($this->month->toString(), 0, 7);
    }

    public function getClosedBy(): ?Uuid
    {
        return $this->closedBy;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return list<RevenueMonthAction> oldest first */
    public function getActions(): array
    {
        $actions = $this->actions->getValues();
        usort($actions, static fn (RevenueMonthAction $a, RevenueMonthAction $b): int => [$a->getAt(), $a->getId()->toRfc4122()] <=> [$b->getAt(), $b->getId()->toRfc4122()]);

        return $actions;
    }
}
