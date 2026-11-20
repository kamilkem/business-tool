<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Payroll\Domain\Exception\InvalidPaycheckAdjustmentException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PayrollMonthInTheFutureException;
use App\Payroll\Domain\Exception\PayrollRunEmptyException;
use App\Payroll\Domain\Exception\PayrollRunNotApprovableException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The pay of one calendar month in the organization's time zone (ADR 0027 §4–9).
 *
 * - Generated as a draft from the month's ended shifts and the rates in effect: one Paycheck per
 *   person with shifts (deactivated members included, ADR 0014), one line per shift, a snapshot.
 *   Rounding is chosen at generation and kept; the currency is the organization's.
 * - A month with nothing to pay (no ended shift, no adjustment) is refused (product owner, #62); so
 *   is a month after the current one. The current month's draft is a preview.
 * - A draft is regenerated (keeping its adjustments and, unless new rounding is given, its rounding),
 *   adjusted and deleted by owners and managers.
 * - Only an owner approves (§7): the month has ended, no shift of it is open, no change request about
 *   it is pending, every line has a rate, no total is negative and the draft equals a recomputation
 *   from the current shifts, rates and currency. The handler then publishes PayrollRunApproved, and
 *   Time tracking locks the month's shifts.
 * - Only an owner reopens an approved run back to draft (§9), which unlocks the shifts. Once paychecks
 *   can be marked paid (#63), a run with a paid paycheck cannot be reopened.
 * - One run per organization and month: the unique constraint below stops two concurrent ones (409).
 */
#[ORM\Entity]
#[ORM\Table(name: 'payroll_run')]
#[ORM\UniqueConstraint(name: self::ONE_PER_MONTH, columns: ['organization_id', 'month'])]
class PayrollRun implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, month); the repository adapter recognizes its violations. */
    public const string ONE_PER_MONTH = 'payroll_run_one_per_month';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The month's first day, a DATE column (ADR 0017, 0027 §4). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $month;

    #[ORM\Column(length: 16, enumType: PayrollRunStatus::class)]
    private PayrollRunStatus $status = PayrollRunStatus::Draft;

    /** ISO 4217, the organization's at the last generation. */
    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 8, enumType: RoundingUnit::class)]
    private RoundingUnit $roundingUnit;

    #[ORM\Column(length: 8, enumType: RoundingDirection::class)]
    private RoundingDirection $roundingDirection;

    /** Shifts of the month still open at the last generation (not on any paycheck). */
    #[ORM\Column]
    private int $openShifts = 0;

    /** Pending shift change requests about the month at the last generation. */
    #[ORM\Column]
    private int $pendingChangeRequests = 0;

    /** @var Collection<int, Paycheck> */
    #[ORM\OneToMany(targetEntity: Paycheck::class, mappedBy: 'payrollRun', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $paychecks;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $generatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    private function __construct(Uuid $id, BusinessPeriod $month, Rounding $rounding, Uuid $actorId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->month = $month->first;
        $this->roundingUnit = $rounding->unit;
        $this->roundingDirection = $rounding->direction;
        $this->paychecks = new ArrayCollection();
        $this->createdBy = $actorId;
        $this->createdAt = $now;
        $this->generatedBy = $actorId;
        $this->generatedAt = $now;
        $this->currency = '';
    }

    /**
     * @param PayrollSources $sources the month's shifts, rates and pending requests now
     * @param BusinessDate   $today   in the organization's time zone
     *
     * @throws PayrollAccessForbiddenException  when the actor is not an owner or manager
     * @throws PayrollMonthInTheFutureException when the month starts after the current one
     * @throws PayrollRunEmptyException         when the month has no ended shift
     */
    public static function generate(
        Uuid $id,
        Rounding $rounding,
        PayrollSources $sources,
        BusinessDate $today,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): self {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        self::assertMonth($sources->month);
        if ($sources->month->first->isAfter(BusinessPeriod::monthOf($today)->first)) {
            throw PayrollMonthInTheFutureException::month($sources->month);
        }

        $run = new self($id, $sources->month, $rounding, $actorId, $now);
        $run->rebuild($sources, $rounding);

        return $run;
    }

    /**
     * Rebuilds the shift lines from the current shifts and rates, with new rounding or the run's.
     * Adjustments stay; a paycheck left with neither lines nor adjustments is dropped.
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayrollRunStatusException       when the run is approved
     * @throws PayrollRunEmptyException        when nothing would be left to pay
     */
    public function regenerate(?Rounding $rounding, PayrollSources $sources, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        $this->assertDraft();
        $this->assertSourcesOfMyMonth($sources);

        $this->rebuild($sources, $rounding ?? $this->rounding());
        $this->generatedBy = $actorId;
        $this->generatedAt = $now;
    }

    /**
     * Checks that the draft may be deleted; the handler then removes it.
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayrollRunStatusException       when the run is approved
     */
    public function delete(Uuid $actorId, MembershipRole $actorRole): void
    {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        $this->assertDraft();
    }

    /**
     * Adds a bonus (+) or deduction (−) to a person's paycheck, creating it for a member with no
     * shifts that month. The handler checks that the user is a member (active or deactivated).
     *
     * @throws PayrollAccessForbiddenException    when the actor is not an owner or manager
     * @throws PayrollRunStatusException          when the run is approved
     * @throws InvalidPaycheckAdjustmentException when the label or amount is invalid
     */
    public function addAdjustment(Uuid $adjustmentId, Uuid $userId, string $label, int $amount, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        $this->assertDraft();

        $paycheck = $this->findPaycheck($userId);
        $adjustment = new PaycheckAdjustment($adjustmentId, $paycheck ?? new Paycheck($this, $userId), $label, $amount, $now);
        if (null === $paycheck) {
            $this->paychecks->add($adjustment->getPaycheck());
        }
        $adjustment->getPaycheck()->addAdjustment($adjustment);
    }

    /**
     * @throws PayrollAccessForbiddenException     when the actor is not an owner or manager
     * @throws PaycheckAdjustmentNotFoundException when the adjustment is not on this run
     * @throws PayrollRunStatusException           when the run is approved
     * @throws InvalidPaycheckAdjustmentException  when the label or amount is invalid
     */
    public function changeAdjustment(Uuid $adjustmentId, string $label, int $amount, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        $adjustment = $this->getAdjustment($adjustmentId);
        $this->assertDraft();

        $adjustment->change($label, $amount, $now);
    }

    /**
     * Removes an adjustment; a paycheck left with neither lines nor adjustments is dropped.
     *
     * @throws PayrollAccessForbiddenException     when the actor is not an owner or manager
     * @throws PaycheckAdjustmentNotFoundException when the adjustment is not on this run
     * @throws PayrollRunStatusException           when the run is approved
     */
    public function removeAdjustment(Uuid $adjustmentId, Uuid $actorId, MembershipRole $actorRole): void
    {
        PayrollAccess::assertCanPrepareRuns($actorId, $actorRole);
        $adjustment = $this->getAdjustment($adjustmentId);
        $this->assertDraft();

        $paycheck = $adjustment->getPaycheck();
        $paycheck->removeAdjustment($adjustment);
        if ($paycheck->isEmpty()) {
            $this->paychecks->removeElement($paycheck);
        }
    }

    /**
     * @param PayrollSources $sources the month's shifts, rates and pending requests now
     * @param BusinessDate   $today   in the organization's time zone
     *
     * @throws PayrollAccessForbiddenException  when the actor is not an owner
     * @throws PayrollRunStatusException        when the run is approved already
     * @throws PayrollRunNotApprovableException when a rule of ADR 0027 §7 is not met (the message says which)
     */
    public function approve(PayrollSources $sources, BusinessDate $today, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        PayrollAccess::assertCanApprove($actorId, $actorRole);
        $this->assertDraft();
        $this->assertSourcesOfMyMonth($sources);

        $month = $this->month();
        if (!$today->isAfter($month->last)) {
            throw PayrollRunNotApprovableException::monthNotEnded($month);
        }
        if ($sources->openShiftCount() > 0) {
            throw PayrollRunNotApprovableException::openShifts($sources->openShiftCount());
        }
        if ($sources->pendingChangeRequests > 0) {
            throw PayrollRunNotApprovableException::pendingChangeRequests($sources->pendingChangeRequests);
        }
        if ($this->paychecks->isEmpty()) {
            throw PayrollRunNotApprovableException::nothingToPay();
        }
        if ($this->linesWithoutRate() > 0) {
            throw PayrollRunNotApprovableException::linesWithoutRate($this->linesWithoutRate());
        }
        foreach ($this->paychecks() as $paycheck) {
            if ($paycheck->total() < 0) {
                throw PayrollRunNotApprovableException::negativeTotal($paycheck->getUserId()->toRfc4122());
            }
        }
        if (!$this->isUpToDateWith($sources)) {
            throw PayrollRunNotApprovableException::outOfDate();
        }

        $this->status = PayrollRunStatus::Approved;
        $this->approvedBy = $actorId;
        $this->approvedAt = $now;
    }

    /**
     * Back to draft, so the month's shifts can change again (ADR 0027 §9). The handler publishes
     * PayrollRunReopened.
     *
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     * @throws PayrollRunStatusException       when the run is a draft
     */
    public function reopen(Uuid $actorId, MembershipRole $actorRole): void
    {
        PayrollAccess::assertCanApprove($actorId, $actorRole);
        if (PayrollRunStatus::Approved !== $this->status) {
            throw PayrollRunStatusException::notApproved($this->id->toRfc4122());
        }

        $this->status = PayrollRunStatus::Draft;
        $this->approvedBy = null;
        $this->approvedAt = null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function month(): BusinessPeriod
    {
        return BusinessPeriod::monthOf($this->month);
    }

    public function getStatus(): PayrollRunStatus
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function rounding(): Rounding
    {
        return new Rounding($this->roundingUnit, $this->roundingDirection);
    }

    public function getOpenShifts(): int
    {
        return $this->openShifts;
    }

    public function getPendingChangeRequests(): int
    {
        return $this->pendingChangeRequests;
    }

    /** @return list<Paycheck> */
    public function paychecks(): array
    {
        return array_values($this->paychecks->toArray());
    }

    public function findPaycheck(Uuid $userId): ?Paycheck
    {
        foreach ($this->paychecks as $paycheck) {
            if ($paycheck->getUserId()->equals($userId)) {
                return $paycheck;
            }
        }

        return null;
    }

    public function linesWithoutRate(): int
    {
        return array_sum(array_map(static fn (Paycheck $paycheck): int => $paycheck->linesWithoutRate(), $this->paychecks()));
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (Paycheck $paycheck): int => $paycheck->total(), $this->paychecks()));
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getGeneratedBy(): Uuid
    {
        return $this->generatedBy;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getApprovedBy(): ?Uuid
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    /** @throws PayrollRunEmptyException */
    private function rebuild(PayrollSources $sources, Rounding $rounding): void
    {
        $lines = $sources->lines($rounding);
        $hasAdjustments = array_any($this->paychecks(), static fn (Paycheck $paycheck): bool => $paycheck->hasAdjustments());
        if ([] === $lines && !$hasAdjustments) {
            throw PayrollRunEmptyException::month($sources->month);
        }

        $this->currency = $sources->currency;
        $this->roundingUnit = $rounding->unit;
        $this->roundingDirection = $rounding->direction;
        $this->openShifts = $sources->openShiftCount();
        $this->pendingChangeRequests = $sources->pendingChangeRequests;

        foreach ($this->paychecks() as $paycheck) {
            $paycheck->replaceLines($lines[$paycheck->getUserId()->toRfc4122()] ?? []);
            unset($lines[$paycheck->getUserId()->toRfc4122()]);
            if ($paycheck->isEmpty()) {
                $this->paychecks->removeElement($paycheck);
            }
        }
        foreach ($lines as $userId => $userLines) {
            $paycheck = new Paycheck($this, Uuid::fromString($userId));
            $paycheck->replaceLines($userLines);
            $this->paychecks->add($paycheck);
        }
    }

    /** The stored lines equal a recomputation with the run's rounding, in the organization's currency. */
    private function isUpToDateWith(PayrollSources $sources): bool
    {
        if ($sources->currency !== $this->currency) {
            return false;
        }
        $expected = [];
        foreach ($sources->lines($this->rounding()) as $userId => $lines) {
            $expected[$userId] = self::signatures($lines);
        }
        $actual = [];
        foreach ($this->paychecks() as $paycheck) {
            $lines = $paycheck->shiftLines();
            if ([] !== $lines) {
                $actual[$paycheck->getUserId()->toRfc4122()] = self::signatures($lines);
            }
        }
        ksort($expected);
        ksort($actual);

        return $expected === $actual;
    }

    /**
     * @param list<ShiftLine> $lines
     *
     * @return list<string> sorted
     */
    private static function signatures(array $lines): array
    {
        $signatures = array_map(static fn (ShiftLine $line): string => $line->signature(), $lines);
        sort($signatures);

        return $signatures;
    }

    /** @throws PaycheckAdjustmentNotFoundException */
    private function getAdjustment(Uuid $adjustmentId): PaycheckAdjustment
    {
        foreach ($this->paychecks as $paycheck) {
            $adjustment = $paycheck->findAdjustment($adjustmentId);
            if (null !== $adjustment) {
                return $adjustment;
            }
        }

        throw PaycheckAdjustmentNotFoundException::forId($adjustmentId->toRfc4122());
    }

    /** @throws PayrollRunStatusException */
    private function assertDraft(): void
    {
        if (PayrollRunStatus::Draft !== $this->status) {
            throw PayrollRunStatusException::notDraft($this->id->toRfc4122());
        }
    }

    private static function assertMonth(BusinessPeriod $period): void
    {
        if (!$period->equals(BusinessPeriod::monthOf($period->first))) {
            throw new \LogicException(\sprintf('A payroll run covers a calendar month, not %s to %s.', $period->first, $period->last));
        }
    }

    private function assertSourcesOfMyMonth(PayrollSources $sources): void
    {
        if (!$sources->month->equals($this->month())) {
            throw new \LogicException(\sprintf('The sources of %s to %s are not those of run "%s".', $sources->month->first, $sources->month->last, $this->id->toRfc4122()));
        }
    }
}
