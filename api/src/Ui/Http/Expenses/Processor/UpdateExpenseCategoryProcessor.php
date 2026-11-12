<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\RenameExpenseCategory\RenameExpenseCategoryCommand;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Application\Query\GetExpenseCategory\GetExpenseCategoryQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseCategoryResource;
use Ui\Http\Expenses\UpdateExpenseCategoryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager renames an expense category of the organization selected by the
 * X-Organization-Id header.
 *
 * @implements ProcessorInterface<UpdateExpenseCategoryInput, ExpenseCategoryResource>
 */
final readonly class UpdateExpenseCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateExpenseCategoryInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseCategoryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId) && null !== $data->name);

        $this->commandBus->handle(new RenameExpenseCategoryCommand($organizationId, $actorId, $categoryId, $data->name));

        /** @var ExpenseCategoryView $view */
        $view = $this->queryBus->ask(new GetExpenseCategoryQuery($organizationId, $actorId, $categoryId));

        return ExpenseCategoryResource::fromView($view);
    }
}
