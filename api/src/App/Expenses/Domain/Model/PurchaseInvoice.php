<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceReadOnlyException;
use App\Income\Domain\Model\VatRates;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Shared\Domain\Time\BusinessDate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice the organization received for something it bought: one expense (ADR 0024).
 *
 * - Country-neutral: it does not know where an importer got it. An imported invoice keeps its
 *   source and its id there (opaque); the pair is unique per organization, so importing the same
 *   invoice again is a conflict (PurchaseInvoiceRepositoryInterface::add()).
 * - What the invoice says (PurchaseInvoiceDetails) is stored as printed: amounts in minor units of
 *   the invoice's currency (ADR 0010), negative ones included (corrections), totals not checked
 *   against each other or the lines. VAT lines use the rates of the organization's country
 *   (VatRates), each once; for an invoice in another currency than the tax currency (the
 *   organization's, copied when the invoice is stored) each line also gives its VAT in the tax
 *   currency.
 * - The issue date is a business date (ADR 0017). The original document, when there is one, is a
 *   PurchaseInvoiceDocument in its own table, never loaded with the invoice.
 * - An owner or manager may enter an invoice by hand (source manual, #54): a cash receipt or a
 *   foreign invoice, which may lack an invoice number or a seller tax ID. Only such an invoice can be
 *   changed (change()) or deleted (assertCanBeDeleted()); an imported one stays as its source says.
 * - It has no category: categorization belongs to its line items and products (#228).
 * - An imported invoice keeps its items as printed (PurchaseInvoiceItem, ADR 0040): never changed,
 *   not checked against the totals. A manual invoice has none.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice')]
#[ORM\UniqueConstraint(name: self::ONE_PER_EXTERNAL_ID, columns: ['organization_id', 'source', 'external_id'])]
// Lists by issue date (#53) within one organization.
#[ORM\Index(name: 'purchase_invoice_organization_issue_date', columns: ['organization_id', 'issue_date'])]
// The seller's invoices within one organization (#55; matching items to products, #233).
#[ORM\Index(name: 'purchase_invoice_organization_seller_tax_id_key', columns: ['organization_id', 'seller_tax_id_key'])]
#[ORM\Index(name: 'purchase_invoice_organization_seller_name_key', columns: ['organization_id', 'seller_name_key'])]
class PurchaseInvoice implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on (organization, source, external id); the repository adapter recognizes its violations. */
    public const string ONE_PER_EXTERNAL_ID = 'purchase_invoice_one_per_external_id';

    public const int MAX_EXTERNAL_ID_LENGTH = 64;
    public const int MAX_INVOICE_NUMBER_LENGTH = 256;
    public const int MAX_SELLER_TAX_ID_LENGTH = 32;
    public const int MAX_SELLER_NAME_LENGTH = 512;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: PurchaseInvoiceSource::class)]
    private PurchaseInvoiceSource $source;

    /** The invoice's id in its source; null for an invoice entered by hand. */
    #[ORM\Column(length: self::MAX_EXTERNAL_ID_LENGTH, nullable: true)]
    private ?string $externalId;

    /** Null only for a manual invoice without one (a receipt). */
    #[ORM\Column(length: self::MAX_INVOICE_NUMBER_LENGTH, nullable: true)]
    private ?string $invoiceNumber;

    /** Null only for a manual invoice without one (a foreign seller, a market stall). */
    #[ORM\Column(length: self::MAX_SELLER_TAX_ID_LENGTH, nullable: true)]
    private ?string $sellerTaxId;

    #[ORM\Column(length: self::MAX_SELLER_NAME_LENGTH)]
    private string $sellerName;

    /** The seller's tax ID as SellerKey::ofTaxId() matches it; null when there is none. */
    #[ORM\Column(length: self::MAX_SELLER_TAX_ID_LENGTH, nullable: true)]
    private ?string $sellerTaxIdKey;

    /** The seller's name as SellerKey::ofName() matches it. */
    #[ORM\Column(length: self::MAX_SELLER_NAME_LENGTH)]
    private string $sellerNameKey;

    /** A DATE column through App\Shared\Infrastructure\Doctrine\Type\BusinessDateType (ADR 0017). */
    #[ORM\Column(type: 'business_date')]
    private BusinessDate $issueDate;

    #[ORM\Column(length: 32, enumType: PurchaseInvoiceKind::class)]
    private PurchaseInvoiceKind $kind;

    /** For a correction: the corrected invoice's id in the same source, when the correction gives it. */
    #[ORM\Column(length: self::MAX_EXTERNAL_ID_LENGTH, nullable: true)]
    private ?string $correctedExternalId;

    /** ISO 4217: the invoice's currency. */
    #[ORM\Column(length: 3)]
    private string $currency;

    /** ISO 4217: the currency VAT is settled in, the organization's when the invoice is stored. */
    #[ORM\Column(length: 3)]
    private string $taxCurrency;

    #[ORM\Column(type: Types::BIGINT)]
    private int $netTotal;

    #[ORM\Column(type: Types::BIGINT)]
    private int $vatTotal;

    #[ORM\Column(type: Types::BIGINT)]
    private int $grossTotal;

    /** @var Collection<int, PurchaseInvoiceVatLine> */
    #[ORM\OneToMany(targetEntity: PurchaseInvoiceVatLine::class, mappedBy: 'purchaseInvoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $vatLines;

    /** @var Collection<int, PurchaseInvoiceItem> */
    #[ORM\OneToMany(targetEntity: PurchaseInvoiceItem::class, mappedBy: 'purchaseInvoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** When the invoice was imported or entered. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $id,
        PurchaseInvoiceSource $source,
        ?string $externalId,
        PurchaseInvoiceDetails $details,
        string $taxCurrency,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->source = $source;
        $this->externalId = $externalId;
        $this->invoiceNumber = $details->invoiceNumber;
        $this->setSeller($details);
        $this->issueDate = $details->issueDate;
        $this->kind = $details->kind;
        $this->correctedExternalId = $details->correctedExternalId;
        $this->currency = $details->currency;
        $this->taxCurrency = $taxCurrency;
        $this->netTotal = $details->netTotal;
        $this->vatTotal = $details->vatTotal;
        $this->grossTotal = $details->grossTotal;
        $this->createdAt = $createdAt;
        $this->vatLines = new ArrayCollection();
        foreach ($details->vatLines as $line) {
            $this->vatLines->add(new PurchaseInvoiceVatLine($this, $line->rate, $line->net, $line->vat, $line->vatInTaxCurrency));
        }
        $this->items = new ArrayCollection();
        foreach ($details->items as $index => $item) {
            $this->items->add(new PurchaseInvoiceItem($this, $index + 1, $item));
        }
    }

    /**
     * Stores an invoice an owner or manager entered by hand (source manual): a cash receipt, a
     * foreign invoice. It has no id in a source and refers to no corrected invoice; the invoice
     * number and the seller tax ID are optional.
     *
     * @param string $countryCode the organization's country, which gives the VAT rates
     * @param string $taxCurrency the organization's currency (ISO 4217)
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPurchaseInvoiceException      when the data breaks a rule
     */
    public static function enter(
        Uuid $id,
        PurchaseInvoiceDetails $details,
        string $countryCode,
        string $taxCurrency,
        Uuid $actorId,
        MembershipRole $actorRole,
        \DateTimeImmutable $now,
    ): self {
        ExpensesAccess::assertCanManage($actorId, $actorRole);

        return new self($id, PurchaseInvoiceSource::Manual, null, self::normalized($details, $countryCode, $taxCurrency, imported: false), strtoupper($taxCurrency), $now);
    }

    /**
     * Replaces what a manual invoice says with the given details, VAT lines included (a rate not
     * given is removed). The tax currency stays the one copied when it was entered. An imported
     * invoice stays as its source says: giving it the details it already has changes nothing (so a
     * client may send them back unchanged), anything else is refused.
     *
     * @param string $countryCode the organization's country, which gives the VAT rates
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPurchaseInvoiceException      when the data breaks a rule
     * @throws PurchaseInvoiceReadOnlyException     when the invoice is imported and the details differ
     */
    public function change(PurchaseInvoiceDetails $details, string $countryCode, Uuid $actorId, MembershipRole $actorRole): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        if (PurchaseInvoiceSource::Manual !== $this->source) {
            if (!$this->says($details)) {
                throw PurchaseInvoiceReadOnlyException::imported($this->id->toRfc4122(), $this->source->value);
            }

            return;
        }

        $details = self::normalized($details, $countryCode, $this->taxCurrency, imported: false);
        $this->invoiceNumber = $details->invoiceNumber;
        $this->setSeller($details);
        $this->issueDate = $details->issueDate;
        $this->kind = $details->kind;
        $this->currency = $details->currency;
        $this->netTotal = $details->netTotal;
        $this->vatTotal = $details->vatTotal;
        $this->grossTotal = $details->grossTotal;
        $this->replaceVatLines($details->vatLines);
    }

    /**
     * Only a manual invoice can be deleted; an imported one is part of what its source holds.
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws PurchaseInvoiceReadOnlyException     when the invoice is imported
     */
    public function assertCanBeDeleted(Uuid $actorId, MembershipRole $actorRole): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        if (PurchaseInvoiceSource::Manual !== $this->source) {
            throw PurchaseInvoiceReadOnlyException::imported($this->id->toRfc4122(), $this->source->value);
        }
    }

    /**
     * Stores an invoice an importer brought in from its source.
     *
     * @param string $externalId  the invoice's id in the source, unique there
     * @param string $countryCode the organization's country, which gives the VAT rates
     * @param string $taxCurrency the organization's currency (ISO 4217)
     *
     * @throws InvalidPurchaseInvoiceException when the source is manual or the data breaks a rule
     */
    public static function import(
        Uuid $id,
        PurchaseInvoiceSource $source,
        string $externalId,
        PurchaseInvoiceDetails $details,
        string $countryCode,
        string $taxCurrency,
        \DateTimeImmutable $now,
    ): self {
        if (PurchaseInvoiceSource::Manual === $source) {
            throw InvalidPurchaseInvoiceException::notImported();
        }
        $externalId = trim($externalId);
        if ('' === $externalId) {
            throw InvalidPurchaseInvoiceException::missingExternalId($source->value);
        }
        self::assertMaxLength('external id', $externalId, self::MAX_EXTERNAL_ID_LENGTH);

        return new self($id, $source, $externalId, self::normalized($details, $countryCode, $taxCurrency, imported: true), strtoupper($taxCurrency), $now);
    }

    /**
     * Gives an imported invoice that has no items the items its document lists, when an importer
     * reads the document again (#230). It changes nothing for an invoice that already has items, so a
     * run done twice never replaces items that may be linked to products (ADR 0040); giving none
     * changes nothing either (an advance invoice lists none).
     *
     * @param list<PurchaseInvoiceItemDetails> $items       in the document's order
     * @param string                           $countryCode the organization's country, which gives the VAT rates
     *
     * @return bool whether the items were set
     *
     * @throws InvalidPurchaseInvoiceException when the invoice was entered by hand or an item breaks a rule
     */
    public function recordItems(array $items, string $countryCode): bool
    {
        if (PurchaseInvoiceSource::Manual === $this->source) {
            throw InvalidPurchaseInvoiceException::itemsOnManual();
        }
        if ([] === $items || !$this->items->isEmpty()) {
            return false;
        }

        // All are checked before the first one is added: either all or none.
        $items = array_map(static fn (PurchaseInvoiceItemDetails $item): PurchaseInvoiceItemDetails => self::normalizedItem($item, $countryCode), $items);
        foreach ($items as $index => $item) {
            $this->items->add(new PurchaseInvoiceItem($this, $index + 1, $item));
        }

        return true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSource(): PurchaseInvoiceSource
    {
        return $this->source;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function getSellerTaxId(): ?string
    {
        return $this->sellerTaxId;
    }

    public function getSellerName(): string
    {
        return $this->sellerName;
    }

    public function getIssueDate(): BusinessDate
    {
        return $this->issueDate;
    }

    public function getKind(): PurchaseInvoiceKind
    {
        return $this->kind;
    }

    public function getCorrectedExternalId(): ?string
    {
        return $this->correctedExternalId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTaxCurrency(): string
    {
        return $this->taxCurrency;
    }

    public function getNetTotal(): int
    {
        return $this->netTotal;
    }

    public function getVatTotal(): int
    {
        return $this->vatTotal;
    }

    public function getGrossTotal(): int
    {
        return $this->grossTotal;
    }

    /** @return list<PurchaseInvoiceVatAmount> highest rate first, exempt and other non-numeric rates last */
    public function getVatLines(): array
    {
        $lines = array_map(static fn (PurchaseInvoiceVatLine $line): PurchaseInvoiceVatAmount => $line->toVatAmount(), $this->vatLines->getValues());
        usort($lines, static fn (PurchaseInvoiceVatAmount $a, PurchaseInvoiceVatAmount $b): int => VatRates::compare($a->rate, $b->rate));

        return $lines;
    }

    /** @return list<PurchaseInvoiceItemDetails> by printed position, then in the document's order */
    public function getItems(): array
    {
        $items = array_map(static fn (PurchaseInvoiceItem $item): array => [$item->getOrdinal(), $item->toDetails()], $this->items->getValues());
        usort($items, static fn (array $a, array $b): int => [$a[1]->position, $a[0]] <=> [$b[1]->position, $b[0]]);

        return array_map(static fn (array $item): PurchaseInvoiceItemDetails => $item[1], $items);
    }

    /**
     * The items by their index, the item's place in the document from 1: what names an item for good,
     * as printed positions may repeat (ADR 0040).
     *
     * @return array<int, PurchaseInvoiceItemDetails> in the order of the index
     */
    public function getItemsByIndex(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[$item->getOrdinal()] = $item->toDetails();
        }
        ksort($items);

        return $items;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function setSeller(PurchaseInvoiceDetails $details): void
    {
        $this->sellerTaxId = $details->sellerTaxId;
        $this->sellerName = $details->sellerName;
        $this->sellerTaxIdKey = SellerKey::ofTaxId($details->sellerTaxId);
        $this->sellerNameKey = SellerKey::ofName($details->sellerName);
    }

    /**
     * True when the details say what the invoice says, in the fields change() replaces; the VAT
     * lines in any order. The corrected invoice's id is not one of them.
     */
    private function says(PurchaseInvoiceDetails $details): bool
    {
        $lines = static function (array $lines): array {
            $keyed = [];
            foreach ($lines as $line) {
                \assert($line instanceof PurchaseInvoiceVatAmount);
                $keyed[$line->rate] = [$line->net, $line->vat, $line->vatInTaxCurrency];
            }
            ksort($keyed);

            return $keyed;
        };

        return self::optionalText($details->invoiceNumber) === $this->invoiceNumber
            && self::optionalText($details->sellerTaxId) === $this->sellerTaxId
            && trim($details->sellerName) === $this->sellerName
            && $details->issueDate->equals($this->issueDate)
            && $details->kind === $this->kind
            && strtoupper(trim($details->currency)) === $this->currency
            && $details->netTotal === $this->netTotal
            && $details->vatTotal === $this->vatTotal
            && $details->grossTotal === $this->grossTotal
            && \count($details->vatLines) === \count($lines($details->vatLines))
            && $lines($details->vatLines) === $lines($this->getVatLines());
    }

    /**
     * Updates lines whose rate stays in place, so the (invoice, rate) key is never inserted twice in
     * one flush; removes the others and adds the new rates.
     *
     * @param list<PurchaseInvoiceVatAmount> $amounts already checked (normalized())
     */
    private function replaceVatLines(array $amounts): void
    {
        $byRate = [];
        foreach ($amounts as $amount) {
            $byRate[$amount->rate] = $amount;
        }
        foreach ($this->vatLines->toArray() as $line) {
            $amount = $byRate[$line->getRate()] ?? null;
            if (null === $amount) {
                $this->vatLines->removeElement($line);
                continue;
            }
            $line->change($amount->net, $amount->vat, $amount->vatInTaxCurrency);
            unset($byRate[$line->getRate()]);
        }
        foreach ($byRate as $amount) {
            $this->vatLines->add(new PurchaseInvoiceVatLine($this, $amount->rate, $amount->net, $amount->vat, $amount->vatInTaxCurrency));
        }
    }

    /**
     * Checks the details and returns them with texts trimmed and currencies upper-cased. An imported
     * invoice needs its number and seller tax ID; a manual one may lack them (blank means none) and
     * refers to no corrected invoice, since it has no source to refer to.
     *
     * @throws InvalidPurchaseInvoiceException
     */
    private static function normalized(PurchaseInvoiceDetails $details, string $countryCode, string $taxCurrency, bool $imported): PurchaseInvoiceDetails
    {
        if ($imported) {
            $invoiceNumber = self::requiredText('invoice number', $details->invoiceNumber ?? '', self::MAX_INVOICE_NUMBER_LENGTH);
            $sellerTaxId = self::requiredText('seller tax ID', $details->sellerTaxId ?? '', self::MAX_SELLER_TAX_ID_LENGTH);
        } else {
            $invoiceNumber = self::optionalText($details->invoiceNumber);
            $sellerTaxId = self::optionalText($details->sellerTaxId);
            if (null !== $invoiceNumber) {
                self::assertMaxLength('invoice number', $invoiceNumber, self::MAX_INVOICE_NUMBER_LENGTH);
            }
            if (null !== $sellerTaxId) {
                self::assertMaxLength('seller tax ID', $sellerTaxId, self::MAX_SELLER_TAX_ID_LENGTH);
            }
        }
        $sellerName = self::requiredText('seller name', $details->sellerName, self::MAX_SELLER_NAME_LENGTH);
        $currency = self::currency('currency', $details->currency);
        $taxCurrency = self::currency('tax currency', $taxCurrency);

        $corrected = self::optionalText($details->correctedExternalId);
        if (null !== $corrected && !$imported) {
            throw InvalidPurchaseInvoiceException::correctedInvoiceOnManual();
        }
        if (null !== $corrected) {
            if (!$details->kind->isCorrection()) {
                throw InvalidPurchaseInvoiceException::correctedInvoiceOnNonCorrection($details->kind->value);
            }
            self::assertMaxLength('corrected invoice id', $corrected, self::MAX_EXTERNAL_ID_LENGTH);
        }

        self::assertValidVatLines($details->vatLines, $countryCode, $currency !== $taxCurrency, $taxCurrency);
        if ([] !== $details->items && !$imported) {
            throw InvalidPurchaseInvoiceException::itemsOnManual();
        }
        $items = array_map(static fn (PurchaseInvoiceItemDetails $item): PurchaseInvoiceItemDetails => self::normalizedItem($item, $countryCode), $details->items);

        return new PurchaseInvoiceDetails(
            $invoiceNumber,
            $sellerTaxId,
            $sellerName,
            $details->issueDate,
            $details->kind,
            $currency,
            $details->netTotal,
            $details->vatTotal,
            $details->grossTotal,
            $details->vatLines,
            $corrected,
            $items,
        );
    }

    /**
     * Checks an item and returns it with texts trimmed (blank optional texts are null) and decimals
     * normalized (DecimalString). Its amounts are not checked against anything (ADR 0040).
     *
     * @throws InvalidPurchaseInvoiceException
     */
    private static function normalizedItem(PurchaseInvoiceItemDetails $item, string $countryCode): PurchaseInvoiceItemDetails
    {
        if ($item->position < 1) {
            throw InvalidPurchaseInvoiceException::invalid('item position', (string) $item->position);
        }
        $vatRate = self::optionalText($item->vatRate);
        if (null !== $vatRate) {
            $vatRate = (VatRates::find($countryCode, $vatRate) ?? throw InvalidPurchaseInvoiceException::unknownVatRate($vatRate, $countryCode))->code;
        }

        return new PurchaseInvoiceItemDetails(
            position: $item->position,
            name: self::requiredText('item name', $item->name, PurchaseInvoiceItem::MAX_NAME_LENGTH),
            sellerCode: self::optionalLimitedText('item seller code', $item->sellerCode, PurchaseInvoiceItem::MAX_SELLER_CODE_LENGTH),
            gtin: self::optionalLimitedText('item GTIN', $item->gtin, PurchaseInvoiceItem::MAX_GTIN_LENGTH),
            unit: self::optionalLimitedText('item unit', $item->unit, PurchaseInvoiceItem::MAX_UNIT_LENGTH),
            quantity: self::decimal('item quantity', $item->quantity, PurchaseInvoiceItem::QUANTITY_INTEGER_DIGITS, PurchaseInvoiceItem::QUANTITY_SCALE),
            unitNetPrice: self::decimal('item unit net price', $item->unitNetPrice, PurchaseInvoiceItem::PRICE_INTEGER_DIGITS, PurchaseInvoiceItem::PRICE_SCALE),
            unitGrossPrice: self::decimal('item unit gross price', $item->unitGrossPrice, PurchaseInvoiceItem::PRICE_INTEGER_DIGITS, PurchaseInvoiceItem::PRICE_SCALE),
            discount: self::decimal('item discount', $item->discount, PurchaseInvoiceItem::PRICE_INTEGER_DIGITS, PurchaseInvoiceItem::PRICE_SCALE),
            netAmount: $item->netAmount,
            grossAmount: $item->grossAmount,
            vatAmount: $item->vatAmount,
            vatRate: $vatRate,
            beforeCorrection: $item->beforeCorrection,
        );
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function optionalLimitedText(string $field, ?string $value, int $maxLength): ?string
    {
        $value = self::optionalText($value);
        if (null !== $value) {
            self::assertMaxLength($field, $value, $maxLength);
        }

        return $value;
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function decimal(string $field, ?string $value, int $maxIntegerDigits, int $maxFractionDigits): ?string
    {
        $value = self::optionalText($value);

        return null === $value ? null : (DecimalString::normalized($value, $maxIntegerDigits, $maxFractionDigits) ?? throw InvalidPurchaseInvoiceException::invalid($field, $value));
    }

    /**
     * @param list<PurchaseInvoiceVatAmount> $lines
     *
     * @throws InvalidPurchaseInvoiceException
     */
    private static function assertValidVatLines(array $lines, string $countryCode, bool $inForeignCurrency, string $taxCurrency): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $rate = VatRates::find($countryCode, $line->rate) ?? throw InvalidPurchaseInvoiceException::unknownVatRate($line->rate, $countryCode);
            if (isset($seen[$rate->code])) {
                throw InvalidPurchaseInvoiceException::duplicateVatRate($rate->code);
            }
            if ($rate->zeroVat && (0 !== $line->vat || !\in_array($line->vatInTaxCurrency, [null, 0], true))) {
                throw InvalidPurchaseInvoiceException::vatOnZeroRate($rate->code);
            }
            if ($inForeignCurrency && null === $line->vatInTaxCurrency) {
                throw InvalidPurchaseInvoiceException::missingVatInTaxCurrency($rate->code, $taxCurrency);
            }
            if (!$inForeignCurrency && null !== $line->vatInTaxCurrency) {
                throw InvalidPurchaseInvoiceException::unexpectedVatInTaxCurrency($rate->code);
            }
            $seen[$rate->code] = true;
        }
    }

    /** Trimmed, or null when blank or not given. */
    private static function optionalText(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function requiredText(string $field, string $value, int $maxLength): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw InvalidPurchaseInvoiceException::blank($field);
        }
        self::assertMaxLength($field, $value, $maxLength);

        return $value;
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function assertMaxLength(string $field, string $value, int $maxLength): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw InvalidPurchaseInvoiceException::tooLong($field, $maxLength);
        }
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function currency(string $field, string $value): string
    {
        $code = strtoupper(trim($value));
        if (1 !== preg_match('/^[A-Z]{3}$/', $code)) {
            throw InvalidPurchaseInvoiceException::invalidCurrency($field, $value);
        }

        return $code;
    }
}
