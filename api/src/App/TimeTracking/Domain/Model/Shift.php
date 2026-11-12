<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Exception\ShiftCannotBeReopenedException;
use App\TimeTracking\Domain\Exception\ShiftChangeForbiddenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use App\TimeTracking\Domain\Exception\ShiftInTheFutureException;
use App\TimeTracking\Domain\Exception\ShiftOverlapException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One person's working time in one organization: open from clock-in until clock-out.
 *
 * The person's own clock-in and clock-out use the server's moments, passed in by the handler from
 * ClockInterface (ADR 0015); the person never sends a time (ADR 0016 §4). Owners and managers are
 * the exception (ADR 0016 §5): they enter a missing shift (record()) and correct a shift's times and
 * note (correct()), and the shift keeps who did it last and when (correctedBy, correctedAt).
 *
 * A shift ends strictly after it starts, and a person has at most one open shift per organization:
 * the model checks it (clockIn() with the open shift from ShiftRepositoryInterface::findOpenByUser()),
 * and the partial unique index below stops two concurrent clock-ins. Times entered by an owner or
 * manager are also never in the future, and never overlap another shift of the same person (an
 * open shift runs on until it ends); the model checks it against the shifts from
 * ShiftRepositoryInterface::findOverlapping(). Clock-in and clock-out cannot cause an overlap,
 * because entered times are never after now.
 *
 * The person is referenced by id (User is another module's aggregate); their membership is never
 * deleted, only deactivated (ADR 0014), so the id stays meaningful.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shift')]
// The predicate is written as PostgreSQL returns it (pg_get_expr), so schema:validate sees no diff.
// Lists by business date (ShiftRepositoryInterface::findStartedIn()) within one organization.
#[ORM\Index(name: 'shift_organization_started_at', columns: ['organization_id', 'started_at'])]
#[ORM\UniqueConstraint(name: self::ONE_OPEN_SHIFT_PER_USER, columns: ['organization_id', 'user_id'], options: ['where' => '(ended_at IS NULL)'])]
class Shift implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the partial unique index on open shifts; the repository adapter recognizes its violations. */
    public const string ONE_OPEN_SHIFT_PER_USER = 'shift_one_open_per_user';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    /** Null while the person is clocked in. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 16, enumType: ShiftSource::class)]
    private ShiftSource $source;

    /** The owner or manager who entered the shift or last changed it; null while it is as clocked. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $correctedBy = null;

    /** When the shift was entered or last changed by correctedBy. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $correctedAt = null;

    private function __construct(Uuid $id, Uuid $userId, \DateTimeImmutable $startedAt, ?string $note, ShiftSource $source)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->startedAt = $startedAt;
        $this->note = $note;
        $this->source = $source;
    }

    /**
     * Opens a shift for the person at the server's now.
     *
     * @param ?Shift $openShift the person's open shift in this organization, if any
     *                          (ShiftRepositoryInterface::findOpenByUser())
     *
     * @throws ShiftAlreadyOpenException when the person is already clocked in
     */
    public static function clockIn(Uuid $id, Uuid $userId, \DateTimeImmutable $now, ?self $openShift, ?string $note = null): self
    {
        if (null !== $openShift) {
            if (!$openShift->userId->equals($userId) || !$openShift->isOpen()) {
                // Only reachable through a bug in the caller.
                throw new \LogicException('The open shift passed to clockIn() must be the same person\'s open shift.');
            }

            throw ShiftAlreadyOpenException::forUser($userId->toRfc4122());
        }

        return new self($id, $userId, $now, $note, ShiftSource::Clock);
    }

    /**
     * An owner or manager enters a shift the person worked but did not clock (ADR 0016 §5). It is
     * always a whole shift, with its end: opening a shift for someone would clock them in, which
     * nobody does for someone else (ADR 0016 §1). The person may be a deactivated member: their past
     * shifts still count (ADR 0014).
     *
     * @param list<Shift> $personsShifts the person's shifts that may overlap the new one
     *                                   (ShiftRepositoryInterface::findOverlapping())
     *
     * @throws ShiftChangeForbiddenException  when the actor is not an owner or manager
     * @throws ShiftEndNotAfterStartException when the end is not after the start
     * @throws ShiftInTheFutureException      when the start or the end is after now
     * @throws ShiftOverlapException          when it overlaps another shift of the person
     */
    public static function record(
        Uuid $id,
        Uuid $userId,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $endedAt,
        ?string $note,
        Uuid $actorId,
        MembershipRole $actorRole,
        array $personsShifts,
        \DateTimeImmutable $now,
    ): self {
        self::assertCanManageShifts($actorId, $actorRole);

        $shift = new self($id, $userId, self::instant($startedAt), self::normalizeNote($note), ShiftSource::Manager);
        $shift->endedAt = self::instant($endedAt);
        $shift->assertValidTimes($shift->startedAt, $shift->endedAt, $personsShifts, $now);
        $shift->markCorrected($actorId, $now);

        return $shift;
    }

    /**
     * An owner or manager changes the shift's times and note: a forgotten clock-out gets its end, a
     * wrong time is fixed. The shift may stay open (a corrected start); an ended shift keeps an end.
     * A change records who made it and when; setting the values the shift already has changes nothing.
     *
     * @param list<Shift> $personsShifts the person's shifts that may overlap the corrected one
     *                                   (ShiftRepositoryInterface::findOverlapping()); this shift is skipped
     *
     * @throws ShiftChangeForbiddenException  when the actor is not an owner or manager
     * @throws ShiftCannotBeReopenedException when an ended shift would lose its end
     * @throws ShiftEndNotAfterStartException when the end is not after the start
     * @throws ShiftInTheFutureException      when the start or the end is after now
     * @throws ShiftOverlapException          when it would overlap another shift of the person
     */
    public function correct(
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        ?string $note,
        Uuid $actorId,
        MembershipRole $actorRole,
        array $personsShifts,
        \DateTimeImmutable $now,
    ): void {
        self::assertCanManageShifts($actorId, $actorRole);

        $startedAt = self::instant($startedAt);
        $endedAt = null === $endedAt ? null : self::instant($endedAt);
        $note = self::normalizeNote($note);

        if (null === $endedAt && null !== $this->endedAt) {
            throw ShiftCannotBeReopenedException::of($this->id->toRfc4122());
        }

        if ($startedAt == $this->startedAt && $endedAt == $this->endedAt && $note === $this->note) {
            return;
        }

        $this->assertValidTimes($startedAt, $endedAt, $personsShifts, $now);

        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
        $this->note = $note;
        $this->markCorrected($actorId, $now);
    }

    /**
     * Only active owners and managers add and change shifts, anyone's, their own included (ADR 0016 §5).
     * The caller passes the actor's role in an organization they are an active member of (ADR 0014).
     *
     * @throws ShiftChangeForbiddenException when the role is employee
     */
    public static function assertCanManageShifts(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw ShiftChangeForbiddenException::forUser($actorId->toRfc4122());
        }
    }

    /**
     * Ends the shift at the server's now.
     *
     * @throws ShiftAlreadyClosedException    when the shift has already ended
     * @throws ShiftEndNotAfterStartException when $now is not after the start
     */
    public function clockOut(\DateTimeImmutable $now): void
    {
        if (null !== $this->endedAt) {
            throw ShiftAlreadyClosedException::of($this->id->toRfc4122());
        }

        if ($now <= $this->startedAt) {
            throw ShiftEndNotAfterStartException::of($this->id->toRfc4122(), $this->startedAt, $now);
        }

        $this->endedAt = $now;
    }

    public function isOpen(): bool
    {
        return null === $this->endedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getSource(): ShiftSource
    {
        return $this->source;
    }

    public function getCorrectedBy(): ?Uuid
    {
        return $this->correctedBy;
    }

    public function getCorrectedAt(): ?\DateTimeImmutable
    {
        return $this->correctedAt;
    }

    /** [start, end) shares a moment with the other shift; an open shift runs on without an end. */
    private function overlaps(\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): bool
    {
        return (null === $endedAt || $this->startedAt < $endedAt)
            && (null === $this->endedAt || $startedAt < $this->endedAt);
    }

    /**
     * @param list<Shift> $personsShifts
     *
     * @throws ShiftEndNotAfterStartException
     * @throws ShiftInTheFutureException
     * @throws ShiftOverlapException
     */
    private function assertValidTimes(\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt, array $personsShifts, \DateTimeImmutable $now): void
    {
        if (null !== $endedAt && $endedAt <= $startedAt) {
            throw ShiftEndNotAfterStartException::of($this->id->toRfc4122(), $startedAt, $endedAt);
        }

        foreach ([$startedAt, $endedAt] as $time) {
            if (null !== $time && $time > $now) {
                throw ShiftInTheFutureException::of($time, $now);
            }
        }

        foreach ($personsShifts as $other) {
            if ($other->id->equals($this->id)) {
                continue;
            }

            if (!$other->userId->equals($this->userId)) {
                // Only reachable through a bug in the caller.
                throw new \LogicException('The shifts passed to check overlaps must be the same person\'s.');
            }

            if ($other->overlaps($startedAt, $endedAt)) {
                throw ShiftOverlapException::with($other->id->toRfc4122(), $other->startedAt, $other->endedAt);
            }
        }
    }

    private function markCorrected(Uuid $actorId, \DateTimeImmutable $now): void
    {
        $this->correctedBy = $actorId;
        $this->correctedAt = $now;
    }

    /**
     * The moment in UTC, to the second: shift times are stored that way (ADR 0010), and so are the
     * times a ShiftChangeRequest asks for.
     */
    public static function instant(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    private static function normalizeNote(?string $note): ?string
    {
        $note = null === $note ? null : trim($note);

        return '' === $note ? null : $note;
    }
}
