<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One close or reopen of an Income month, kept for good (ADR 0034 §1). Created only through
 * RevenueMonth; its id is generated here, as for Paycheck. Tenant-aware itself, so a query on actions
 * alone is restricted to the current organization too.
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_month_action')]
class RevenueMonthAction implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'actions')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RevenueMonth $month,
        #[ORM\Column(length: 8, enumType: RevenueMonthActionKind::class)]
        private RevenueMonthActionKind $kind,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $actorId,
        #[ORM\Column]
        private \DateTimeImmutable $at,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMonth(): RevenueMonth
    {
        return $this->month;
    }

    public function getKind(): RevenueMonthActionKind
    {
        return $this->kind;
    }

    public function getActorId(): Uuid
    {
        return $this->actorId;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
