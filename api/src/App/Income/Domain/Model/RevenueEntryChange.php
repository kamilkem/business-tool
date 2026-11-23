<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One write of a revenue entry, kept for good (ADR 0034 §4): an append-only row that is never changed
 * or deleted. It refers to the entry by id only, since a deleted entry is gone, and copies its
 * register's name, business date and currency as they were. The snapshots before and after are null
 * for a created and for a deleted entry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_entry_change')]
#[ORM\Index(name: 'revenue_entry_change_entry', columns: ['organization_id', 'entry_id'])]
#[ORM\Index(name: 'revenue_entry_change_business_date', columns: ['organization_id', 'business_date'])]
class RevenueEntryChange implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $entryId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $registerId;

    #[ORM\Column(length: 64)]
    private string $registerName;

    #[ORM\Column(type: 'business_date')]
    private BusinessDate $businessDate;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 8, enumType: RevenueEntryChangeKind::class)]
    private RevenueEntryChangeKind $kind;

    /** The end-of-day close the write came from; null for a write by hand. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $dayCloseId;

    /** @var array{payments: list<array{paymentMethodId: string, paymentMethodName: string, amount: int}>, total: int, vatLines: list<array{rate: string, gross: int, vat: int}>, note: string|null}|null */
    #[ORM\Column(name: 'before_snapshot', type: 'json', nullable: true)]
    private ?array $before;

    /** @var array{payments: list<array{paymentMethodId: string, paymentMethodName: string, amount: int}>, total: int, vatLines: list<array{rate: string, gross: int, vat: int}>, note: string|null}|null */
    #[ORM\Column(name: 'after_snapshot', type: 'json', nullable: true)]
    private ?array $after;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $changedBy;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    /**
     * @param RevenueEntry $entry the entry after the write, or before it when it was deleted
     */
    public function __construct(
        Uuid $id,
        RevenueEntry $entry,
        RevenueEntryChangeKind $kind,
        ?RevenueEntrySnapshot $before,
        ?RevenueEntrySnapshot $after,
        ?Uuid $dayCloseId,
        Uuid $changedBy,
        \DateTimeImmutable $changedAt,
    ) {
        $valid = match ($kind) {
            RevenueEntryChangeKind::Created => null === $before && null !== $after,
            RevenueEntryChangeKind::Changed => null !== $before && null !== $after,
            RevenueEntryChangeKind::Deleted => null !== $before && null === $after,
            RevenueEntryChangeKind::Booked => null !== $after,
        };
        if (!$valid) {
            throw new \LogicException(\sprintf('A "%s" change has the wrong snapshots.', $kind->value));
        }

        $this->id = $id;
        $this->entryId = $entry->getId();
        $this->registerId = $entry->getRegister()->getId();
        $this->registerName = $entry->getRegister()->getName();
        $this->businessDate = $entry->getBusinessDate();
        $this->currency = $entry->getCurrency();
        $this->kind = $kind;
        $this->dayCloseId = $dayCloseId;
        $this->before = $before?->toArray();
        $this->after = $after?->toArray();
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEntryId(): Uuid
    {
        return $this->entryId;
    }

    public function getRegisterId(): Uuid
    {
        return $this->registerId;
    }

    public function getRegisterName(): string
    {
        return $this->registerName;
    }

    public function getBusinessDate(): BusinessDate
    {
        return $this->businessDate;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getKind(): RevenueEntryChangeKind
    {
        return $this->kind;
    }

    public function getDayCloseId(): ?Uuid
    {
        return $this->dayCloseId;
    }

    public function getBefore(): ?RevenueEntrySnapshot
    {
        return null === $this->before ? null : RevenueEntrySnapshot::fromArray($this->before);
    }

    public function getAfter(): ?RevenueEntrySnapshot
    {
        return null === $this->after ? null : RevenueEntrySnapshot::fromArray($this->after);
    }

    public function getChangedBy(): Uuid
    {
        return $this->changedBy;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
