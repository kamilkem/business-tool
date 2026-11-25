<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\DecimalString;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceItem;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Purchasing's record of one item of a purchase invoice (ADR 0041 §3): the fields Expenses stored,
 * copied once and never changed, and Purchasing's own interpretation of them: the product it is
 * linked to, how it was linked and its package size.
 *
 * - It is named by the invoice's id and the item's index in the document, unique together, which makes
 *   recording an invoice's items again a no-op (RecordPurchasedItemsHandler). The invoice is not a
 *   foreign key: Purchasing refers to Expenses by id only, and imported invoices are never deleted.
 * - Until a person or matching links it to a product (#233, #242), the item is
 *   `unassigned` with no product and no package size, and counts in spend only.
 * - Quantities and prices are NUMERIC decimal strings (ADR 0040); the package size is a decimal string
 *   in the product's base unit (L, kg, pc) per one unit of the item's quantity (ADR 0041 §3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchased_item')]
#[ORM\UniqueConstraint(name: self::ONE_PER_INVOICE_ITEM, columns: ['invoice_id', 'item_index'])]
class PurchasedItem implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (invoice, item index). */
    public const string ONE_PER_INVOICE_ITEM = 'purchased_item_one_per_invoice_item';

    /** Digits of the package size: NUMERIC(22, 6). */
    public const int PACKAGE_SIZE_SCALE = 6;
    public const int PACKAGE_SIZE_INTEGER_DIGITS = 16;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Expenses' invoice, by id only. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $invoiceId;

    /** The item's place in the document, from 1. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $itemIndex;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $invoiceIssueDate;

    #[ORM\Column(length: 32)]
    private string $invoiceKind;

    /** ISO 4217: the invoice's currency. */
    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: PurchaseInvoice::MAX_SELLER_TAX_ID_LENGTH, nullable: true)]
    private ?string $sellerTaxId;

    #[ORM\Column(length: PurchaseInvoice::MAX_SELLER_NAME_LENGTH)]
    private string $sellerName;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(length: PurchaseInvoiceItem::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(length: PurchaseInvoiceItem::MAX_SELLER_CODE_LENGTH, nullable: true)]
    private ?string $sellerCode;

    #[ORM\Column(length: PurchaseInvoiceItem::MAX_GTIN_LENGTH, nullable: true)]
    private ?string $gtin;

    #[ORM\Column(length: PurchaseInvoiceItem::MAX_UNIT_LENGTH, nullable: true)]
    private ?string $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: PurchaseInvoiceItem::QUANTITY_SCALE, nullable: true)]
    private ?string $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: PurchaseInvoiceItem::PRICE_SCALE, nullable: true)]
    private ?string $unitNetPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: PurchaseInvoiceItem::PRICE_SCALE, nullable: true)]
    private ?string $unitGrossPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: PurchaseInvoiceItem::PRICE_SCALE, nullable: true)]
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

    /** The product it is linked to; null until it is. No ON DELETE: a product with items cannot be deleted (Product::assertDeletable()). */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column(length: 16, enumType: PurchasedItemStatus::class)]
    private PurchasedItemStatus $status = PurchasedItemStatus::Unassigned;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::PACKAGE_SIZE_SCALE, nullable: true)]
    private ?string $packageSize = null;

    /** When Purchasing recorded it. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Uuid $id, PurchasedItemDetails $details, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->invoiceId = $details->invoiceId;
        $this->itemIndex = $details->itemIndex;
        $this->invoiceIssueDate = $details->invoiceIssueDate;
        $this->invoiceKind = $details->invoiceKind;
        $this->currency = $details->currency;
        $this->sellerTaxId = $details->sellerTaxId;
        $this->sellerName = $details->sellerName;
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
        $this->createdAt = $createdAt;
    }

    /** Records an item that Purchasing does not have yet: `unassigned`, with no product and no package size. */
    public static function record(Uuid $id, PurchasedItemDetails $details, \DateTimeImmutable $now): self
    {
        return new self($id, $details, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoiceId(): Uuid
    {
        return $this->invoiceId;
    }

    public function getItemIndex(): int
    {
        return $this->itemIndex;
    }

    public function getInvoiceIssueDate(): BusinessDate
    {
        return $this->invoiceIssueDate;
    }

    public function getInvoiceKind(): string
    {
        return $this->invoiceKind;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSellerTaxId(): ?string
    {
        return $this->sellerTaxId;
    }

    public function getSellerName(): string
    {
        return $this->sellerName;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSellerCode(): ?string
    {
        return $this->sellerCode;
    }

    public function getGtin(): ?string
    {
        return $this->gtin;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    /** A decimal string with trailing zeros trimmed (`0.7`, `-2`). */
    public function getQuantity(): ?string
    {
        return DecimalString::trimmed($this->quantity);
    }

    public function getUnitNetPrice(): ?string
    {
        return DecimalString::trimmed($this->unitNetPrice);
    }

    public function getUnitGrossPrice(): ?string
    {
        return DecimalString::trimmed($this->unitGrossPrice);
    }

    public function getDiscount(): ?string
    {
        return DecimalString::trimmed($this->discount);
    }

    public function getNetAmount(): ?int
    {
        return $this->netAmount;
    }

    public function getGrossAmount(): ?int
    {
        return $this->grossAmount;
    }

    public function getVatAmount(): ?int
    {
        return $this->vatAmount;
    }

    public function getVatRate(): ?string
    {
        return $this->vatRate;
    }

    public function isBeforeCorrection(): bool
    {
        return $this->beforeCorrection;
    }

    public function getProductId(): ?Uuid
    {
        return $this->product?->getId();
    }

    public function getStatus(): PurchasedItemStatus
    {
        return $this->status;
    }

    /** A decimal string with trailing zeros trimmed; null until known. */
    public function getPackageSize(): ?string
    {
        return DecimalString::trimmed($this->packageSize);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
