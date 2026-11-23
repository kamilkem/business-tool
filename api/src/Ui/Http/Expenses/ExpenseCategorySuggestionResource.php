<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Expenses\Application\Query\SuggestExpenseCategory\ExpenseCategorySuggestionView;
use Ui\Http\Expenses\Provider\ExpenseCategorySuggestionProvider;

/**
 * The category to suggest for an expense of a seller from the selected organization's history
 * (#55), e.g. while an owner or manager enters one by hand. Imported invoices get the same
 * suggestion stored, marked `categorySuggested`.
 */
#[ApiResource(
    shortName: 'ExpenseCategorySuggestion',
    operations: [
        new Get(
            uriTemplate: '/expense-category-suggestion',
            openapi: new Operation(
                summary: 'Suggests a category for an expense of a seller from the selected organization\'s history.',
                description: 'Owners and managers only (403 otherwise). The seller is matched by sellerTaxId (spaces and dashes ignored) when given, else by sellerName (case and surrounding spaces ignored). The suggestion is the active category that owners and managers chose most often for that seller\'s expenses; pre-filled categories nobody confirmed do not count, and a tie goes to the category of the seller\'s latest expense by issue date. Both fields are null when there is nothing to suggest.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A parameter is too long.'),
                ],
            ),
            queryParameterValidationEnabled: false,
            provider: ExpenseCategorySuggestionProvider::class,
            parameters: [
                'sellerTaxId' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'The seller\'s tax ID.',
                ),
                'sellerName' => new QueryParameter(
                    schema: ['type' => 'string'],
                    description: 'The seller\'s name, used when there is no tax ID.',
                ),
            ],
        ),
    ],
)]
final readonly class ExpenseCategorySuggestionResource
{
    public function __construct(
        /** The suggested category; null when there is nothing to suggest. */
        public ?string $categoryId,
        /** Its name; null when there is nothing to suggest. */
        public ?string $categoryName,
    ) {
    }

    public static function fromView(ExpenseCategorySuggestionView $view): self
    {
        return new self($view->categoryId, $view->categoryName);
    }
}
