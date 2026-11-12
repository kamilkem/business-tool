<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * One VAT line of a RevenueEntry, created and changed only through the entry. The primary key
 * (entry, rate) keeps each rate once per entry. Tenant-aware like every business row.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'revenue_entry_vat_line')]
class RevenueVatLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'vatLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private RevenueEntry $revenueEntry,
        #[ORM\Id]
        #[ORM\Column(length: 8)]
        private string $rate,
        #[ORM\Column]
        private int $gross,
        #[ORM\Column]
        private int $vat,
    ) {
    }

    public function change(int $gross, int $vat): void
    {
        $this->gross = $gross;
        $this->vat = $vat;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function toVatAmount(): VatAmount
    {
        return new VatAmount($this->rate, $this->gross, $this->vat);
    }
}
