<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a DayClose did to one storage, created only through it (ADR 0033 §6): the storage's mode at
 * the close (a later mode change applies from the next close), the total entered (counted value or
 * deposited value) and, for a counted storage, its expected opening: the ledger balance just before
 * the close, kept as a value derived from the ledger. The pieces are DayCloseLines.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_day_close_storage')]
#[ORM\Index(name: 'cash_day_close_storage_storage', columns: ['storage_id'])]
class DayCloseStorage implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'storages')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DayClose $dayClose,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private CashStorage $storage,
        #[ORM\Column(length: 8, enumType: CashStorageMode::class)]
        private CashStorageMode $mode,
        /** Minor units: the counted value of what stays in a counted storage, or the value put into a deposit one. */
        #[ORM\Column(type: Types::BIGINT)]
        private int $total,
        /** Minor units: a counted storage's balance just before the close; null for a deposit storage. */
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        private ?int $expectedOpening,
    ) {
    }

    public function getStorage(): CashStorage
    {
        return $this->storage;
    }

    public function getMode(): CashStorageMode
    {
        return $this->mode;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getExpectedOpening(): ?int
    {
        return $this->expectedOpening;
    }

    /** What the storage adds to the cash income: a count minus the expected opening, or the deposit. */
    public function cashIncome(): int
    {
        return $this->total - ($this->expectedOpening ?? 0);
    }
}
