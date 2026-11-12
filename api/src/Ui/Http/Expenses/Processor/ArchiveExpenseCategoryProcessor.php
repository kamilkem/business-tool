<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\ArchiveExpenseCategory\ArchiveExpenseCategoryCommand;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Application\Query\GetExpenseCategory\GetExpenseCategoryQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseCategoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager archives an expense category of the organization selected by the
 * X-Organization-Id header: it is no longer offered for new expenses, and the expenses that have it
 * keep it.
 *
 * @implements ProcessorInterface<null, ExpenseCategoryResource>
 */
final readonly class ArchiveExpenseCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseCategoryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId));

        $this->commandBus->handle(new ArchiveExpenseCategoryCommand($organizationId, $actorId, $categoryId));

        /** @var ExpenseCategoryView $view */
        $view = $this->queryBus->ask(new GetExpenseCategoryQuery($organizationId, $actorId, $categoryId));

        return ExpenseCategoryResource::fromView($view);
    }
}
