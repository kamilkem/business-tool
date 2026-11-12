<?php

declare(strict_types=1);

namespace Ui\Http\Expenses;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Expenses\Processor\AddExpenseCategoryProcessor;
use Ui\Http\Expenses\Processor\ArchiveExpenseCategoryProcessor;
use Ui\Http\Expenses\Processor\RestoreExpenseCategoryProcessor;
use Ui\Http\Expenses\Processor\UpdateExpenseCategoryProcessor;
use Ui\Http\Expenses\Provider\ExpenseCategoryCollectionProvider;
use Ui\Http\Expenses\Provider\ExpenseCategoryProvider;
use Ui\Http\Expenses\Provider\UpdateExpenseCategoryInputProvider;

/**
 * An expense category of the organization selected by the X-Organization-Id header: what an expense
 * was for (rent, food, ...). A new organization starts with a default set for its country. Owners
 * and managers only: anyone else gets a 403. Categories are archived, never deleted, and an archived
 * one can be restored.
 */
#[ApiResource(
    shortName: 'ExpenseCategory',
    operations: [
        new GetCollection(
            uriTemplate: '/expense-categories',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the expense categories of the selected organization.',
                description: 'Active categories only, unless includeArchived is true; active ones first, then by name. Owners and managers only (403 otherwise).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: ExpenseCategoryCollectionProvider::class,
            parameters: [
                'includeArchived' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list archived categories (to restore them, and for the expenses that still have them).',
                    castToNativeType: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/expense-categories/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One expense category of the selected organization, active or archived.',
                description: 'Owners and managers only (403 otherwise). A category that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                ],
            ),
            provider: ExpenseCategoryProvider::class,
        ),
        new Post(
            uriTemplate: '/expense-categories',
            openapi: new Operation(
                summary: 'Adds an expense category to the selected organization.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed, 1 to 64 characters (422), and unique in the organization among active and archived categories, compared as written (409).',
                responses: [
                    '201' => new Response('The new category, active.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('A category of the organization already has the name.'),
                    '422' => new Response('The name is missing, blank or too long.'),
                ],
            ),
            input: AddExpenseCategoryInput::class,
            read: false,
            processor: AddExpenseCategoryProcessor::class,
        ),
        new Patch(
            uriTemplate: '/expense-categories/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Renames an expense category.',
                description: 'Owners and managers only (403 otherwise); a category that is not the selected organization\'s is a 404. An archived category can be renamed too. The rules of POST apply to the name (409, 422); renaming to the current name changes nothing.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                    '409' => new Response('Another category of the organization has the name.'),
                    '422' => new Response('The name is blank or too long.'),
                ],
            ),
            input: UpdateExpenseCategoryInput::class,
            provider: UpdateExpenseCategoryInputProvider::class,
            processor: UpdateExpenseCategoryProcessor::class,
        ),
        new Post(
            uriTemplate: '/expense-categories/{id}/archive',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Archives an expense category.',
                description: 'Owners and managers only (403 otherwise); a category that is not the selected organization\'s is a 404. The category is no longer offered for new expenses; the expenses that have it keep it. The request has no body. Archiving an archived category changes nothing.',
                responses: [
                    '200' => new Response('The archived category.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: ArchiveExpenseCategoryProcessor::class,
        ),
        new Post(
            uriTemplate: '/expense-categories/{id}/restore',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Restores an archived expense category.',
                description: 'Owners and managers only (403 otherwise); a category that is not the selected organization\'s is a 404. The category is offered for new expenses again. The request has no body. Restoring an active category changes nothing.',
                responses: [
                    '200' => new Response('The active category.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The category is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: RestoreExpenseCategoryProcessor::class,
        ),
    ],
)]
final readonly class ExpenseCategoryResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** True once archived: the category is not offered for new expenses. */
        public bool $archived,
        public \DateTimeImmutable $createdAt,
        /** When the category was archived; null while it is active. */
        public ?\DateTimeImmutable $archivedAt,
    ) {
    }

    public static function fromView(ExpenseCategoryView $view): self
    {
        return new self($view->id, $view->name, $view->archived, $view->createdAt, $view->archivedAt);
    }
}
