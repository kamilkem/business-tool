<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\DecimalString;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceItem;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Purchasing\Domain\Exception\InvalidPackageSizeException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\ItemReader;
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
 * - A person links it with link(): any change by a person makes the item `confirmed` when it has a
 *   product and `unassigned` when it has none, whatever it was before (ADR 0041 §3). A person taking
 *   the product away also keeps the item out of matching until a person links it again (ADR 0042 §4).
 * - Matching (ADR 0042) changes only the items that are not `confirmed`, with match(): it sets the
 *   product, the status (`assigned` or `suggested`) and the package size, and remembers that the item
 *   was matched, so that the model is not asked about it again.
 * - An item no product fits may carry a proposal (ADR 0044): the name, measure and category the model
 *   proposes for a product to create. Items are grouped by the proposed name's key. A person accepts it
 *   (the items are linked to the new product, which clears it) or dismisses it (the key is remembered, so
 *   the same name is not proposed for this item again). Matching only ever writes the proposal; it never
 *   creates a product.
 * - Quantities and prices are NUMERIC decimal strings (ADR 0040); the package size is a decimal string
 *   in the product's base unit (L, kg, pc) per one unit of the item's quantity (ADR 0041 §3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchased_item')]
#[ORM\UniqueConstraint(name: self::ONE_PER_INVOICE_ITEM, columns: ['invoice_id', 'item_index'])]
#[ORM\Index(name: self::BY_STATUS_AND_DATE, columns: ['organization_id', 'status', 'invoice_issue_date'])]
class PurchasedItem implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (invoice, item index). */
    public const string ONE_PER_INVOICE_ITEM = 'purchased_item_one_per_invoice_item';

    /** The review queue's index: an organization's items in a status, by issue date. */
    public const string BY_STATUS_AND_DATE = 'purchased_item_by_status_and_date';

    /** Digits of the package size: NUMERIC(22, 6). */
    public const int PACKAGE_SIZE_SCALE = 6;
    public const int PACKAGE_SIZE_INTEGER_DIGITS = 16;

    /** The shape of a package size as a request writes it, for the Ui's validation. */
    public const string PACKAGE_SIZE_PATTERN = '/^\d{1,16}(\.\d{1,6})?$/D';

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

    /** A person took the product away: matching leaves the item alone until a person links it again (ADR 0042 §4). */
    #[ORM\Column(options: ['default' => false])]
    private bool $matchingSkipped = false;

    /** When matching last went through the item with the model, whatever came out; null: never (ADR 0042 §7). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $matchedAt = null;

    /** The name of the product the model proposes for the item; null: no proposal (ADR 0044). */
    #[ORM\Column(length: Product::NAME_MAX_LENGTH, nullable: true)]
    private ?string $proposedName = null;

    /** ItemReader::fullKey() of the proposed name: what proposals are grouped by. Null exactly when there is no proposal. */
    #[ORM\Column(length: Product::NAME_MAX_LENGTH, nullable: true)]
    private ?string $proposedNameKey = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ProductMeasure::class)]
    private ?ProductMeasure $proposedMeasure = null;

    /** Null when the model chose none; set null when the category is deleted. */
    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductCategory $proposedCategory = null;

    /** The key of the proposal a person dismissed for this item: the same name is not proposed for it again. */
    #[ORM\Column(length: Product::NAME_MAX_LENGTH, nullable: true)]
    private ?string $dismissedProposalKey = null;

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

    /**
     * A person links the item to a product, or takes it off its product (`$product` null), and sets
     * its package size: the product's base unit (L, kg, pc) per one unit of the item's quantity. The
     * caller sends the package size the item is to have, so the current one when it is not changing. The
     * item is `confirmed` with a product and `unassigned` without one; the package size is kept either
     * way, and counts only for a product with a measure other than `none`.
     *
     * @param ?string $packageSize a positive decimal with at most 6 decimals, null for unknown
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPackageSizeException            when the package size is not a positive decimal
     */
    public function link(?Product $product, ?string $packageSize, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        $packageSize = self::packageSizeFor($packageSize);

        $this->product = $product;
        $this->packageSize = $packageSize;
        $this->status = null === $product ? PurchasedItemStatus::Unassigned : PurchasedItemStatus::Confirmed;
        $this->matchingSkipped = null === $product;
        $this->clearProposal();
    }

    /**
     * Matching links the item to a product, or notes that it found none (`$product` null: the item
     * keeps what it had), and that the model went through it. `assigned` is a confident match,
     * `suggested` one for a person to confirm. Never for a `confirmed` item.
     *
     * @param ?string $packageSize a positive decimal with at most 6 decimals, null for unknown
     *
     * @throws \LogicException            for a `confirmed` item or one a person took off its product: matching must not read them
     * @throws InvalidPackageSizeException when the package size is not a positive decimal
     */
    public function match(?Product $product, ?string $packageSize, PurchasedItemStatus $status, \DateTimeImmutable $now): void
    {
        if (!$this->isMatchable()) {
            throw new \LogicException('A confirmed item, or one taken off its product by a person, is not changed by matching.');
        }
        if (null !== $product && !\in_array($status, [PurchasedItemStatus::Assigned, PurchasedItemStatus::Suggested], true)) {
            throw new \LogicException('Matching links an item as assigned or suggested.');
        }

        $this->matchedAt = $now;
        if (null === $product) {
            return;
        }
        $this->clearProposal();
        $this->product = $product;
        $this->packageSize = self::packageSizeFor($packageSize);
        $this->status = $status;
    }

    /**
     * Matching proposes a product for the item, which no product of the catalog is (ADR 0044), or, with
     * `$name` or `$measure` null, finds nothing to propose, which replaces an earlier proposal. A blank or too long
     * name, one with no letters or digits, and the name a person dismissed for this item are no proposal.
     * Never for an item that is linked to a product, `confirmed` or taken off its product by a person.
     *
     * @throws \LogicException for an item matching must not change
     */
    public function propose(?string $name, ?ProductMeasure $measure, ?ProductCategory $category): void
    {
        if (!$this->isMatchable() || null !== $this->product) {
            throw new \LogicException('Only an unlinked item that matching may change can get a proposal.');
        }

        $name = null === $name ? '' : trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $key = ItemReader::fullKey($name);
        if (null === $measure || '' === $name || mb_strlen($name) > Product::NAME_MAX_LENGTH || '' === $key || $key === $this->dismissedProposalKey) {
            $this->clearProposal();

            return;
        }

        $this->proposedName = $name;
        $this->proposedNameKey = $key;
        $this->proposedMeasure = $measure;
        $this->proposedCategory = $category;
    }

    /**
     * A person dismisses the item's proposal: the item stays unassigned and the same name is not
     * proposed for it again. Nothing happens for an item with no proposal.
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     */
    public function dismissProposal(Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        if (null === $this->proposedNameKey) {
            return;
        }

        $this->dismissedProposalKey = $this->proposedNameKey;
        $this->clearProposal();
    }

    public function hasProposal(): bool
    {
        return null !== $this->proposedNameKey;
    }

    public function getProposedName(): ?string
    {
        return $this->proposedName;
    }

    public function getProposedNameKey(): ?string
    {
        return $this->proposedNameKey;
    }

    public function getProposedMeasure(): ?ProductMeasure
    {
        return $this->proposedMeasure;
    }

    public function getProposedCategory(): ?ProductCategory
    {
        return $this->proposedCategory;
    }

    private function clearProposal(): void
    {
        $this->proposedName = null;
        $this->proposedNameKey = null;
        $this->proposedMeasure = null;
        $this->proposedCategory = null;
    }

    /** Whether matching may read and change the item: not `confirmed`, and not taken off its product by a person. */
    public function isMatchable(): bool
    {
        return PurchasedItemStatus::Confirmed !== $this->status && !$this->matchingSkipped;
    }

    public function getMatchedAt(): ?\DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function isMatchingSkipped(): bool
    {
        return $this->matchingSkipped;
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

    public function getProduct(): ?Product
    {
        return $this->product;
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

    /**
     * The package size to store: null, or the given positive decimal in normal form.
     *
     * @throws InvalidPackageSizeException
     */
    private static function packageSizeFor(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = DecimalString::normalized($value, self::PACKAGE_SIZE_INTEGER_DIGITS, self::PACKAGE_SIZE_SCALE);
        if (null === $normalized || '0' === $normalized || str_starts_with($normalized, '-')) {
            throw InvalidPackageSizeException::of($value);
        }

        return $normalized;
    }
}
