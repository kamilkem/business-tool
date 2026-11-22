<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * How the organization's end-of-day closes get their business date (ADR 0033 §5): at most one per
 * organization (the unique constraint below). An organization without one uses
 * DayCloseRule::default(); the first change by an owner or manager creates it. The setting affects
 * closes only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_day_close_settings')]
#[ORM\UniqueConstraint(name: self::UNIQUE_ORGANIZATION, columns: ['organization_id'])]
class DayCloseSettings implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on the organization; the repository adapter recognizes its violations. */
    public const string UNIQUE_ORGANIZATION = 'cash_day_close_settings_organization_unique';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: DayCloseDateMode::class)]
    private DayCloseDateMode $mode;

    /** HH:MM, local time in the organization's time zone (DayEndTime). */
    #[ORM\Column(length: 5)]
    private string $dayEndsAt;

    /** The owner or manager who changed it last. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $updatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Uuid $id, DayCloseRule $rule, Uuid $actorId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->mode = $rule->mode;
        $this->dayEndsAt = $rule->dayEndsAt->toString();
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    /**
     * The organization's first change from the defaults.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public static function create(Uuid $id, DayCloseRule $rule, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);

        return new self($id, $rule, $actorId, $now);
    }

    /**
     * Applies to closes from now on; earlier closes keep their dates. Changing to the current rule
     * changes nothing, not even who changed it last.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function change(DayCloseRule $rule, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if ($rule->equals($this->rule())) {
            return;
        }

        $this->mode = $rule->mode;
        $this->dayEndsAt = $rule->dayEndsAt->toString();
        $this->updatedBy = $actorId;
        $this->updatedAt = $now;
    }

    public function rule(): DayCloseRule
    {
        return new DayCloseRule($this->mode, DayEndTime::fromString($this->dayEndsAt));
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
