<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use App\Expenses\Domain\Model\ExpenseCategory;
use Symfony\Component\Validator\Constraints as Assert;

/** Uniqueness of the name in the organization is the domain's (409). */
final readonly class AddExpenseCategoryInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared as written ("Food" and "food" are two names). */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: ExpenseCategory::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
    ) {
    }
}
