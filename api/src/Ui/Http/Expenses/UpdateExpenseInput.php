<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateExpenseInputProvider pre-fills it from the current
 * expense and API Platform applies the request body onto it, so a field the client did not send
 * keeps its value.
 */
final class UpdateExpenseInput
{
    /** A category of the organization, not archived unless it is the expense's already; null clears it. */
    #[Assert\Uuid]
    public ?string $categoryId = null;

    public static function fromView(PurchaseInvoiceView $view): self
    {
        $input = new self();
        $input->categoryId = $view->categoryId;

        return $input;
    }
}
