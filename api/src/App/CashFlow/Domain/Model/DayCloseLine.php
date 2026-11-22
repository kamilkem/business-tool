<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pieces of one denomination in one storage at a DayClose, created only through it (ADR 0033 §6):
 * absolute for a counted storage (every active denomination, zeros included: the count replaces the
 * balance), added for a deposit storage (only what was put in). Whether it is a count is the mode of
 * its DayCloseStorage. The foreign keys to the storage and the denomination have no ON DELETE: a
 * used one cannot be deleted.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_day_close_line')]
#[ORM\Index(name: 'cash_day_close_line_storage', columns: ['storage_id'])]
#[ORM\Index(name: 'cash_day_close_line_denomination', columns: ['denomination_id'])]
class DayCloseLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DayClose $dayClose,
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

    public function changePieces(int $pieces): void
    {
        $this->pieces = $pieces;
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
