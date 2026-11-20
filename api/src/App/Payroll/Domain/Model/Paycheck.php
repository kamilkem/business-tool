<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One person's pay in a payroll run (ADR 0027 §6): a line per ended shift of the month and any
 * number of adjustments. Pay for hours is the exact sum of paid seconds × rate, divided by 3600 once
 * and rounded half up; the total adds the adjustments. Created and changed through the PayrollRun.
 */
#[ORM\Entity]
#[ORM\Table(name: 'paycheck')]
#[ORM\UniqueConstraint(name: 'paycheck_one_per_run_and_user', columns: ['payroll_run_id', 'user_id'])]
class Paycheck implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** @var Collection<int, PaycheckLine> */
    #[ORM\OneToMany(targetEntity: PaycheckLine::class, mappedBy: 'paycheck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @var Collection<int, PaycheckAdjustment> */
    #[ORM\OneToMany(targetEntity: PaycheckAdjustment::class, mappedBy: 'paycheck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $adjustments;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'paychecks')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PayrollRun $payrollRun,
        /** The member (App\User) paid, active or deactivated (ADR 0014). */
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
    ) {
        // A child of the run, like Membership of Organization: nobody refers to it before it exists.
        $this->id = Uuid::v7();
        $this->lines = new ArrayCollection();
        $this->adjustments = new ArrayCollection();
    }

    /**
     * Replaces the shift lines with a recomputation. A line of the same shift is updated in place
     * (its key is the paycheck and the shift), so removing and adding it in one flush never collides.
     *
     * @param list<ShiftLine> $lines
     */
    public function replaceLines(array $lines): void
    {
        $byShift = [];
        foreach ($lines as $line) {
            $byShift[$line->shiftId->toRfc4122()] = $line;
        }
        foreach ($this->lines->toArray() as $existing) {
            $shiftId = $existing->getShiftId()->toRfc4122();
            if (isset($byShift[$shiftId])) {
                $existing->replaceWith($byShift[$shiftId]);
                unset($byShift[$shiftId]);
            } else {
                $this->lines->removeElement($existing);
            }
        }
        foreach ($byShift as $line) {
            $this->lines->add(new PaycheckLine($this, $line->shiftId, $line));
        }
    }

    public function addAdjustment(PaycheckAdjustment $adjustment): void
    {
        $this->adjustments->add($adjustment);
    }

    public function removeAdjustment(PaycheckAdjustment $adjustment): void
    {
        $this->adjustments->removeElement($adjustment);
    }

    public function findAdjustment(Uuid $id): ?PaycheckAdjustment
    {
        foreach ($this->adjustments as $adjustment) {
            if ($adjustment->getId()->equals($id)) {
                return $adjustment;
            }
        }

        return null;
    }

    /** No shift lines and no adjustments: nothing to pay, so the run drops it. */
    public function isEmpty(): bool
    {
        return $this->lines->isEmpty() && $this->adjustments->isEmpty();
    }

    public function hasAdjustments(): bool
    {
        return !$this->adjustments->isEmpty();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    /** @return list<ShiftLine> by business date, then shift id */
    public function shiftLines(): array
    {
        $lines = array_map(static fn (PaycheckLine $line): ShiftLine => $line->toShiftLine(), $this->lines->toArray());
        usort($lines, static fn (ShiftLine $a, ShiftLine $b): int => [$a->date->toString(), $a->shiftId->toRfc4122()] <=> [$b->date->toString(), $b->shiftId->toRfc4122()]);

        return $lines;
    }

    /** @return list<PaycheckAdjustment> oldest first */
    public function adjustments(): array
    {
        return array_values($this->adjustments->toArray());
    }

    public function paidSeconds(): int
    {
        return array_sum(array_map(static fn (PaycheckLine $line): int => $line->toShiftLine()->paidSeconds, $this->lines->toArray()));
    }

    /** Σ(paid seconds × rate) / 3600, rounded half up once (ADR 0027 §6); lines without a rate count 0. */
    public function payForHours(): int
    {
        return ShiftLine::roundHalfUp(array_sum(array_map(static fn (PaycheckLine $line): int => $line->toShiftLine()->exactAmount(), $this->lines->toArray())));
    }

    public function adjustmentsTotal(): int
    {
        return array_sum(array_map(static fn (PaycheckAdjustment $adjustment): int => $adjustment->getAmount(), $this->adjustments->toArray()));
    }

    public function total(): int
    {
        return $this->payForHours() + $this->adjustmentsTotal();
    }

    public function linesWithoutRate(): int
    {
        return \count(array_filter($this->lines->toArray(), static fn (PaycheckLine $line): bool => null === $line->toShiftLine()->rate));
    }
}
