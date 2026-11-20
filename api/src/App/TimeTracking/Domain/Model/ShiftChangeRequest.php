<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestAlreadyDecidedException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestChangesNothingException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestDecisionForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNoteRequiredException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use App\TimeTracking\Domain\Exception\ShiftPeriodLockedException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A member asks an owner or manager to change their shifts: to enter a shift they worked but did
 * not clock, or to give one of their own shifts new times. People never change shift times
 * themselves (ADR 0016 §5); the note explains the change to whoever decides it.
 *
 * Any active member submits requests, for themselves only. Owners and managers approve or reject
 * them, their own included (as they correct their own shifts directly). A decision is final.
 *
 * The request checks only what does not depend on other shifts when it is submitted: the end is
 * after the start, nothing is in the future, the shift is the requester's, and an ended shift is
 * not reopened. Approving applies the change through the Shift aggregate (Shift::record() or
 * Shift::correct()) with the approver as the corrector, so the shift rules, overlap included, run
 * against the shifts as they are at approval; the shift is changed first and the request is
 * approved after it (approve()), so a change the rules refuse leaves the request pending.
 *
 * Nobody asks to change a locked period (ADR 0027 §8): submitting is refused when the shift's start
 * or the asked start is on a locked business date (ShiftPeriodLocks), and approving a request that
 * was pending when the period was locked is refused by Shift::record() or Shift::correct().
 *
 * The requester is referenced by id (User is another module's aggregate); memberships are never
 * deleted (ADR 0014), so the id stays meaningful.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shift_change_request')]
// Lists newest first, all or by status (ShiftChangeRequestRepositoryInterface::findAll()).
#[ORM\Index(name: 'shift_change_request_organization_submitted_at', columns: ['organization_id', 'submitted_at'])]
class ShiftChangeRequest implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** The member who asked; the shift is theirs. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column(length: 16, enumType: ShiftChangeRequestKind::class)]
    private ShiftChangeRequestKind $kind;

    /**
     * The shift to give new times to; for a missing shift, the shift entered when the request was
     * approved (null until then).
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $shiftId;

    /** The start asked for. */
    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    /** The end asked for; null only for new times that keep an open shift open (a corrected start). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt;

    #[ORM\Column(type: 'text')]
    private string $note;

    #[ORM\Column(length: 16, enumType: ShiftChangeRequestStatus::class)]
    private ShiftChangeRequestStatus $status = ShiftChangeRequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    /** The owner or manager who approved or rejected the request; null while it is pending. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Why the request was rejected, if the owner or manager said. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    private function __construct(
        Uuid $id,
        Uuid $userId,
        ShiftChangeRequestKind $kind,
        ?Uuid $shiftId,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $note,
        \DateTimeImmutable $now,
    ) {
        $note = trim($note);
        if ('' === $note) {
            throw ShiftChangeRequestNoteRequiredException::create();
        }

        $startedAt = Shift::instant($startedAt);
        $endedAt = null === $endedAt ? null : Shift::instant($endedAt);

        if (null !== $endedAt && $endedAt <= $startedAt) {
            throw ShiftEndNotAfterStartException::requested($startedAt, $endedAt);
        }

        foreach ([$startedAt, $endedAt] as $time) {
            if (null !== $time && $time > $now) {
                throw ShiftInTheFutureException::of($time, $now);
            }
        }

        $this->id = $id;
        $this->userId = $userId;
        $this->kind = $kind;
        $this->shiftId = $shiftId;
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
        $this->note = $note;
        $this->submittedAt = $now;
    }

    /**
     * The requester worked a shift they did not clock. It is asked for whole, with its end: an open
     * shift is only ever opened by clocking in (ADR 0016 §1).
     *
     * @throws ShiftChangeRequestNoteRequiredException when the note is blank
     * @throws ShiftEndNotAfterStartException          when the end is not after the start
     * @throws ShiftInTheFutureException               when the start or the end is after now
     * @throws ShiftPeriodLockedException              when the start is on a locked business date
     */
    public static function forMissingShift(
        Uuid $id,
        Uuid $requesterId,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $endedAt,
        string $note,
        \DateTimeImmutable $now,
        ShiftPeriodLocks $locks,
    ): self {
        $locks->assertUnlocked($startedAt);

        return new self($id, $requesterId, ShiftChangeRequestKind::MissingShift, null, $startedAt, $endedAt, $note, $now);
    }

    /**
     * The requester asks for new times for one of their own shifts: a forgotten clock-out, a wrong
     * start. An open shift may stay open (a null end); an ended one keeps an end.
     *
     * @throws ShiftNotFoundException                    when the shift is not the requester's
     * @throws ShiftCannotBeReopenedException            when an ended shift would lose its end
     * @throws ShiftChangeRequestChangesNothingException when the times are the shift's own
     * @throws ShiftChangeRequestNoteRequiredException   when the note is blank
     * @throws ShiftEndNotAfterStartException            when the end is not after the start
     * @throws ShiftInTheFutureException                 when the start or the end is after now
     * @throws ShiftPeriodLockedException                when the shift's start or the asked start is on a locked business date
     */
    public static function forShift(
        Uuid $id,
        Uuid $requesterId,
        Shift $shift,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $note,
        \DateTimeImmutable $now,
        ShiftPeriodLocks $locks,
    ): self {
        if (!$shift->getUserId()->equals($requesterId)) {
            // Someone else's shift is not one the requester may ask about: it does not exist for them.
            throw ShiftNotFoundException::withId($shift->getId()->toRfc4122());
        }

        $locks->assertUnlocked($shift->getStartedAt());
        $locks->assertUnlocked($startedAt);

        if (null === $endedAt && !$shift->isOpen()) {
            throw ShiftCannotBeReopenedException::of($shift->getId()->toRfc4122());
        }

        $request = new self($id, $requesterId, ShiftChangeRequestKind::NewTimes, $shift->getId(), $startedAt, $endedAt, $note, $now);

        if ($request->startedAt == $shift->getStartedAt() && $request->endedAt == $shift->getEndedAt()) {
            throw ShiftChangeRequestChangesNothingException::forShift($shift->getId()->toRfc4122());
        }

        return $request;
    }

    /** Owners and managers see every request; everyone sees their own. */
    public function isVisibleTo(Uuid $actorId, MembershipRole $actorRole): bool
    {
        return $actorRole->canManageOrganization() || $this->userId->equals($actorId);
    }

    /**
     * Only active owners and managers decide requests, their own included, and only pending ones.
     * The caller passes the actor's role in an organization they are an active member of (ADR 0014).
     *
     * @throws ShiftChangeRequestDecisionForbiddenException when the actor is an employee
     * @throws ShiftChangeRequestAlreadyDecidedException    when the request is not pending
     */
    public function assertCanBeDecidedBy(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw ShiftChangeRequestDecisionForbiddenException::forUser($actorId->toRfc4122());
        }

        if (ShiftChangeRequestStatus::Pending !== $this->status) {
            throw ShiftChangeRequestAlreadyDecidedException::of($this->id->toRfc4122(), $this->status->value);
        }
    }

    /**
     * Marks the request approved, once its change has been applied to the shift (by the same actor):
     * the shift entered for a missing shift, or the shift that got the new times.
     *
     * @throws ShiftChangeRequestDecisionForbiddenException when the actor is an employee
     * @throws ShiftChangeRequestAlreadyDecidedException    when the request is not pending
     */
    public function approve(Uuid $actorId, MembershipRole $actorRole, Uuid $shiftId, \DateTimeImmutable $now): void
    {
        $this->assertCanBeDecidedBy($actorId, $actorRole);

        if (null !== $this->shiftId && !$this->shiftId->equals($shiftId)) {
            // Only reachable through a bug in the caller.
            throw new \LogicException('A request for new times is approved with the shift it is about.');
        }

        $this->shiftId = $shiftId;
        $this->decide(ShiftChangeRequestStatus::Approved, $actorId, $now);
    }

    /**
     * Rejects the request, optionally saying why; the shifts stay as they are.
     *
     * @throws ShiftChangeRequestDecisionForbiddenException when the actor is an employee
     * @throws ShiftChangeRequestAlreadyDecidedException    when the request is not pending
     */
    public function reject(Uuid $actorId, MembershipRole $actorRole, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->assertCanBeDecidedBy($actorId, $actorRole);

        $reason = null === $reason ? null : trim($reason);
        $this->rejectionReason = '' === $reason ? null : $reason;
        $this->decide(ShiftChangeRequestStatus::Rejected, $actorId, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getKind(): ShiftChangeRequestKind
    {
        return $this->kind;
    }

    public function getShiftId(): ?Uuid
    {
        return $this->shiftId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getStatus(): ShiftChangeRequestStatus
    {
        return $this->status;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getDecidedBy(): ?Uuid
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    private function decide(ShiftChangeRequestStatus $status, Uuid $actorId, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->decidedBy = $actorId;
        $this->decidedAt = $now;
    }
}
