<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceVatLineView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One VAT rate of an expense entered by hand, as printed, in integer minor units of the expense's
 * currency; negative for a correction. Whether the rate exists in the organization's country, VAT 0
 * at a 0% or exempt rate, and vatInTaxCurrency given exactly when the expense is in another currency
 * than the organization's, the domain checks (422). Properties, not constructor parameters, for the
 * reason given in Ui\Http\Income\PaymentLineInput.
 */
final class ExpenseVatLineInput
{
    /** A rate code of GET /vat-rates: "23", "zw". */
    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    public string $rate;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public int $net;

    #[Assert\NotNull]
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public int $vat;

    /** The VAT in the organization's currency, required when the expense is in another currency; null otherwise. */
    #[Assert\Range(min: -ExpenseInputRules::MAX, max: ExpenseInputRules::MAX)]
    public ?int $vatInTaxCurrency = null;

    public static function fromView(PurchaseInvoiceVatLineView $view): self
    {
        $line = new self();
        $line->rate = $view->rate;
        $line->net = $view->net;
        $line->vat = $view->vat;
        $line->vatInTaxCurrency = $view->vatInTaxCurrency;

        return $line;
    }

    /** For Assert\Unique: lines are the same when their rates are. */
    public static function rateOf(mixed $line): mixed
    {
        return $line instanceof self && isset($line->rate) ? $line->rate : $line;
    }
}
