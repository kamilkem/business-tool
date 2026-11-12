<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Domain\Model\ExpenseCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateExpenseCategoryInputProvider pre-fills it from the
 * current category and API Platform applies the request body onto it, so a field the client did not
 * send keeps its value.
 */
final class UpdateExpenseCategoryInput
{
    /** Trimmed; unique in the organization, compared as written. */
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: ExpenseCategory::NAME_MAX_LENGTH, normalizer: 'trim')]
    public ?string $name = null;

    public static function fromView(ExpenseCategoryView $view): self
    {
        $input = new self();
        $input->name = $view->name;

        return $input;
    }
}
