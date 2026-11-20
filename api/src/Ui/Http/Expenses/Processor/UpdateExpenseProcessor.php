<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\CategorizePurchaseInvoice\CategorizePurchaseInvoiceCommand;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceQuery;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseResource;
use Ui\Http\Expenses\UpdateExpenseInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager sets or clears the category of an expense of the organization selected by the
 * X-Organization-Id header.
 *
 * @implements ProcessorInterface<UpdateExpenseInput, ExpenseResource>
 */
final readonly class UpdateExpenseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateExpenseInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $invoiceId = $uriVariables['id'];
        \assert(\is_string($invoiceId));

        $this->commandBus->handle(new CategorizePurchaseInvoiceCommand($organizationId, $actorId, $invoiceId, $data->categoryId));

        /** @var PurchaseInvoiceView $view */
        $view = $this->queryBus->ask(new GetPurchaseInvoiceQuery($organizationId, $actorId, $invoiceId));

        return ExpenseResource::fromView($view);
    }
}
