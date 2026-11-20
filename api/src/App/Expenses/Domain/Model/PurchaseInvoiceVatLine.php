<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One VAT line of a PurchaseInvoice, created only through the invoice. The primary key
 * (invoice, rate) keeps each rate once per invoice. Tenant-aware like every business row.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice_vat_line')]
class PurchaseInvoiceVatLine implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'vatLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PurchaseInvoice $purchaseInvoice,
        #[ORM\Id]
        #[ORM\Column(length: 8)]
        private string $rate,
        #[ORM\Column(type: Types::BIGINT)]
        private int $net,
        #[ORM\Column(type: Types::BIGINT)]
        private int $vat,
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        private ?int $vatInTaxCurrency,
    ) {
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    /** The invoice changes its line at this rate (PurchaseInvoice::change()). */
    public function change(int $net, int $vat, ?int $vatInTaxCurrency): void
    {
        $this->net = $net;
        $this->vat = $vat;
        $this->vatInTaxCurrency = $vatInTaxCurrency;
    }

    public function toVatAmount(): PurchaseInvoiceVatAmount
    {
        return new PurchaseInvoiceVatAmount($this->rate, $this->net, $this->vat, $this->vatInTaxCurrency);
    }
}
