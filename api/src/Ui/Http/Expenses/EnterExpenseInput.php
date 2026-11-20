<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiProperty;
use App\Expenses\Domain\Model\PurchaseInvoice;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An expense entered by hand (#54): a cash receipt, a foreign invoice. Amounts in integer minor
 * units of `currency` (ADR 0010), stored as given: totals are not checked against each other or the
 * lines. Whether the VAT rates are the country's, the domain checks (422). Properties, not
 * constructor parameters, for the reason given in Ui\Http\Income\PaymentLineInput.
 */
final class EnterExpenseInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_NAME_LENGTH, normalizer: 'trim')]
    public string $sellerName;

    /** The seller's tax ID, if it has one; blank or null for none. */
    #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_TAX_ID_LENGTH, normalizer: 'trim')]
    public ?string $sellerTaxId = null;

    /** The receipt's or invoice's number, if it has one; blank or null for none. */
    #[Assert\Length(max: PurchaseInvoice::MAX_INVOICE_NUMBER_LENGTH, normalizer: 'trim')]
    public ?string $invoiceNumber = null;

    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The issue date (YYYY-MM-DD).'])]
    #[Assert\NotBlank]
    #[Assert\Date]
    public string $issueDate;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['regular', 'correction', 'advance', 'settlement', 'simplified', 'advance_correction', 'settlement_correction'], 'default' => 'regular'])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ExpenseInputRules::class, 'kinds'])]
    public string $kind = 'regular';

    /** ISO 4217 code of the amounts; the organization's currency when not given. */
    #[Assert\Currency]
    public ?string $currency = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public int $netTotal;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public int $vatTotal;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public int $grossTotal;

    /** @var list<ExpenseVatLineInput> the VAT breakdown as printed, each rate once; optional */
    #[Assert\Unique(normalizer: [ExpenseVatLineInput::class, 'rateOf'], message: 'Each VAT rate may appear only once.')]
    #[Assert\Valid]
    public array $vatLines = [];

    /** A category of the organization that is not archived; null leaves the expense uncategorized. */
    #[Assert\Uuid]
    public ?string $categoryId = null;
}
