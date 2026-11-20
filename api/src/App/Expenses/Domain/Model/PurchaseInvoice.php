<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Expenses\Domain\Exception\ExpenseCategoryArchivedException;
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
 *   changed (change()) or deleted (assertCanBeDeleted()); an imported one stays as its source says,
 *   except for its category.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice')]
#[ORM\UniqueConstraint(name: self::ONE_PER_EXTERNAL_ID, columns: ['organization_id', 'source', 'external_id'])]
// Lists by issue date (#53) within one organization.
#[ORM\Index(name: 'purchase_invoice_organization_issue_date', columns: ['organization_id', 'issue_date'])]
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

    /** The expense category (#48); null until categorized (categorize()). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $categoryId = null;

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
        $this->sellerTaxId = $details->sellerTaxId;
        $this->sellerName = $details->sellerName;
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
     * client may send them back unchanged with a new category), anything else is refused.
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
        $this->sellerTaxId = $details->sellerTaxId;
        $this->sellerName = $details->sellerName;
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

    /**
     * Sets the expense category, or clears it with null. Whatever the source, an invoice's category
     * can always be changed. An archived category cannot be newly assigned, but an invoice that has
     * it keeps it, so assigning its current category again changes nothing.
     *
     * @param ?ExpenseCategory $category a category of the invoice's organization
     *                                   (ExpenseCategoryRepositoryInterface::findById()), or null
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws ExpenseCategoryArchivedException     when the category is archived and not the current one
     */
    public function categorize(?ExpenseCategory $category, Uuid $actorId, MembershipRole $actorRole): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        if (null === $category) {
            $this->categoryId = null;

            return;
        }
        if (null !== $this->categoryId && $this->categoryId->equals($category->getId())) {
            return;
        }
        if ($category->isArchived()) {
            throw ExpenseCategoryArchivedException::of($category->getName());
        }

        $this->categoryId = $category->getId();
    }

    public function getCategoryId(): ?Uuid
    {
        return $this->categoryId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
        );
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
