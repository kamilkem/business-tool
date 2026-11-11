<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\TimeTracking\Domain\Exception\ShiftAlreadyClosedException;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Exception\ShiftEndNotAfterStartException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One person's working time in one organization: open from clock-in until clock-out.
 *
 * Times are the server's moments, passed in by the handler from ClockInterface (ADR 0015); the
 * client never sends a time (ADR 0016 §4). A shift ends strictly after it starts, and a person has
 * at most one open shift per organization: the model checks it (clockIn() with the open shift from
 * ShiftRepositoryInterface::findOpenByUser()), and the partial unique index below stops two
 * concurrent clock-ins.
 *
 * The person is referenced by id (User is another module's aggregate); their membership is never
 * deleted, only deactivated (ADR 0014), so the id stays meaningful.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shift')]
// The predicate is written as PostgreSQL returns it (pg_get_expr), so schema:validate sees no diff.
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

    private function __construct(Uuid $id, Uuid $userId, \DateTimeImmutable $startedAt, ?string $note)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->startedAt = $startedAt;
        $this->note = $note;
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

        return new self($id, $userId, $now, $note);
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
}
