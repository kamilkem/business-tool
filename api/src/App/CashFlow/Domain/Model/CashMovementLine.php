<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a CashMovement, created only through it: pieces of one denomination in one storage,
 * signed for an adjustment or a transfer, absolute for a count. The primary key keeps each
 * denomination once per storage and movement. The foreign keys to the storage and the denomination
 * have no ON DELETE: a used one cannot be deleted (ADR 0033 §2, §3). Tenant-aware like every
 * business row.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_movement_line')]
#[ORM\Index(name: 'cash_movement_line_storage', columns: ['storage_id'])]
#[ORM\Index(name: 'cash_movement_line_denomination', columns: ['denomination_id'])]
class CashMovementLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CashMovement $movement,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private CashStorage $storage,
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Denomination $denomination,
        #[ORM\Column]
        private int $pieces,
    ) {
    }

    public function getStorage(): CashStorage
    {
        return $this->storage;
    }

    public function getDenomination(): Denomination
    {
        return $this->denomination;
    }

    public function getPieces(): int
    {
        return $this->pieces;
    }
}
