<?php

declare(strict_types=1);

namespace App\TimeTracking\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\TimeTracking\Domain\Exception\TimeTrackingSettingsChangeForbiddenException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The organization's time tracking settings (#33): at most one per organization (the unique
 * constraint below). An organization without one uses the defaults (MaxShiftLength::default()); the
 * first change by an owner or manager creates it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_tracking_settings')]
#[ORM\UniqueConstraint(name: self::UNIQUE_ORGANIZATION, columns: ['organization_id'])]
class TimeTrackingSettings implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const string UNIQUE_ORGANIZATION = 'time_tracking_settings_organization_unique';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'smallint')]
    private int $maxShiftHours;

    /** The owner or manager who changed them last. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $updatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Uuid $id, MaxShiftLength $maxShiftLength, Uuid $actorId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->maxShiftHours = $maxShiftLength->hours;
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    /**
     * The organization's first change from the defaults.
     *
     * @throws TimeTrackingSettingsChangeForbiddenException when the actor is not an owner or manager
     */
    public static function create(Uuid $id, MaxShiftLength $maxShiftLength, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        self::assertCanChange($actorId, $actorRole);

        return new self($id, $maxShiftLength, $actorId, $now);
    }

    /**
     * Applies from now on: shifts already reported stay reported. Setting the current values changes
     * nothing, not even who changed them last.
     *
     * @throws TimeTrackingSettingsChangeForbiddenException when the actor is not an owner or manager
     */
    public function change(MaxShiftLength $maxShiftLength, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        self::assertCanChange($actorId, $actorRole);
        if ($maxShiftLength->equals($this->maxShiftLength())) {
            return;
        }

        $this->maxShiftHours = $maxShiftLength->hours;
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    /** @throws TimeTrackingSettingsChangeForbiddenException when the role is employee */
    public static function assertCanChange(Uuid $actorId, MembershipRole $actorRole): void
    {
        if (!$actorRole->canManageOrganization()) {
            throw TimeTrackingSettingsChangeForbiddenException::forUser($actorId->toRfc4122());
        }
    }

    public function maxShiftLength(): MaxShiftLength
    {
        return MaxShiftLength::ofHours($this->maxShiftHours);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUpdatedBy(): Uuid
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
