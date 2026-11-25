<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One item of an imported PurchaseInvoice, as printed and never changed (ADR 0040), created only
 * through the invoice. The primary key is the invoice and the item's order in the document
 * (`ordinal`), since printed positions may repeat. Amounts are minor units (ADR 0010); quantities,
 * unit prices and the discount are NUMERIC decimal strings. Tenant-aware like every business row.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice_item')]
class PurchaseInvoiceItem implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int MAX_NAME_LENGTH = 512;
    public const int MAX_SELLER_CODE_LENGTH = 64;
    public const int MAX_GTIN_LENGTH = 32;
    public const int MAX_UNIT_LENGTH = 256;

    /** Digits of a quantity: NUMERIC(22, 6). */
    public const int QUANTITY_SCALE = 6;
    public const int QUANTITY_INTEGER_DIGITS = 16;

    /** Digits of a unit price or a discount: NUMERIC(22, 8). */
    public const int PRICE_SCALE = 8;
    public const int PRICE_INTEGER_DIGITS = 14;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(length: self::MAX_SELLER_CODE_LENGTH, nullable: true)]
    private ?string $sellerCode;

    #[ORM\Column(length: self::MAX_GTIN_LENGTH, nullable: true)]
    private ?string $gtin;

    #[ORM\Column(length: self::MAX_UNIT_LENGTH, nullable: true)]
    private ?string $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::QUANTITY_SCALE, nullable: true)]
    private ?string $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::PRICE_SCALE, nullable: true)]
    private ?string $unitNetPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::PRICE_SCALE, nullable: true)]
    private ?string $unitGrossPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::PRICE_SCALE, nullable: true)]
    private ?string $discount;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $netAmount;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $grossAmount;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $vatAmount;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $vatRate;

    #[ORM\Column]
    private bool $beforeCorrection;

    /** @param PurchaseInvoiceItemDetails $details already checked (PurchaseInvoice) */
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PurchaseInvoice $purchaseInvoice,
        /** The item's place in the document, from 1. */
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        private int $ordinal,
        PurchaseInvoiceItemDetails $details,
    ) {
        $this->position = $details->position;
        $this->name = $details->name;
        $this->sellerCode = $details->sellerCode;
        $this->gtin = $details->gtin;
        $this->unit = $details->unit;
        $this->quantity = $details->quantity;
        $this->unitNetPrice = $details->unitNetPrice;
        $this->unitGrossPrice = $details->unitGrossPrice;
        $this->discount = $details->discount;
        $this->netAmount = $details->netAmount;
        $this->grossAmount = $details->grossAmount;
        $this->vatAmount = $details->vatAmount;
        $this->vatRate = $details->vatRate;
        $this->beforeCorrection = $details->beforeCorrection;
    }

    public function getOrdinal(): int
    {
        return $this->ordinal;
    }

    public function toDetails(): PurchaseInvoiceItemDetails
    {
        return new PurchaseInvoiceItemDetails(
            position: $this->position,
            name: $this->name,
            sellerCode: $this->sellerCode,
            gtin: $this->gtin,
            unit: $this->unit,
            quantity: DecimalString::trimmed($this->quantity),
            unitNetPrice: DecimalString::trimmed($this->unitNetPrice),
            unitGrossPrice: DecimalString::trimmed($this->unitGrossPrice),
            discount: DecimalString::trimmed($this->discount),
            netAmount: $this->netAmount,
            grossAmount: $this->grossAmount,
            vatAmount: $this->vatAmount,
            vatRate: $this->vatRate,
            beforeCorrection: $this->beforeCorrection,
        );
    }
}
