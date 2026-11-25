<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiProperty;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Expenses\Domain\Model\PurchaseInvoice;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateExpenseInputProvider pre-fills it from the current
 * expense and API Platform applies the request body onto it, so a field the client did not send
 * keeps its value. A `vatLines` list that is sent replaces the current one whole; null or [] removes
 * the breakdown. Only a manual expense takes new details; an imported one only its own (409 otherwise).
 */
final class UpdateExpenseInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_NAME_LENGTH, normalizer: 'trim')]
    public ?string $sellerName = null;

    /** Blank or null removes it. */
    #[Assert\Length(max: PurchaseInvoice::MAX_SELLER_TAX_ID_LENGTH, normalizer: 'trim')]
    public ?string $sellerTaxId = null;

    /** Blank or null removes it. */
    #[Assert\Length(max: PurchaseInvoice::MAX_INVOICE_NUMBER_LENGTH, normalizer: 'trim')]
    public ?string $invoiceNumber = null;

    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The issue date (YYYY-MM-DD).'])]
    #[Assert\NotBlank]
    #[Assert\Date]
    public ?string $issueDate = null;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['regular', 'correction', 'advance', 'settlement', 'simplified', 'advance_correction', 'settlement_correction']])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ExpenseInputRules::class, 'kinds'])]
    public ?string $kind = null;

    /** ISO 4217 code of the amounts. */
    #[Assert\NotBlank]
    #[Assert\Currency]
    public ?string $currency = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public ?int $netTotal = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public ?int $vatTotal = null;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public ?int $grossTotal = null;

    /** @var list<ExpenseVatLineInput>|null the VAT breakdown as printed, each rate once; empty or null removes it */
    #[Assert\Unique(normalizer: [ExpenseVatLineInput::class, 'rateOf'], message: 'Each VAT rate may appear only once.')]
    #[Assert\Valid]
    public ?array $vatLines = null;

    public static function fromView(PurchaseInvoiceView $view): self
    {
        $input = new self();
        $input->sellerName = $view->sellerName;
        $input->sellerTaxId = $view->sellerTaxId;
        $input->invoiceNumber = $view->invoiceNumber;
        $input->issueDate = $view->issueDate;
        $input->kind = $view->kind;
        $input->currency = $view->currency;
        $input->netTotal = $view->netTotal;
        $input->vatTotal = $view->vatTotal;
        $input->grossTotal = $view->grossTotal;
        $input->vatLines = array_map(ExpenseVatLineInput::fromView(...), $view->vatLines);

        return $input;
    }
}
