<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Entity;

use App\CashFlow\Domain\Model\CashStorage;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Test-only row that refers to a cash storage with a foreign key that has no ON DELETE, as the
 * ledger's and the close's lines will (#181, #185): it stands in for a cash record until they exist.
 * Mapped in the test env only (config/packages/doctrine.yaml).
 */
#[ORM\Entity]
class CashStorageReference
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CashStorage::class)]
        #[ORM\JoinColumn(nullable: false)]
        public CashStorage $storage,
    ) {
        $this->id = Uuid::v7();
    }
}
