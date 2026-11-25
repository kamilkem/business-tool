<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\EnterPurchaseInvoice\EnterPurchaseInvoiceCommand;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceQuery;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Expenses\EnterExpenseInput;
use Ui\Http\Expenses\ExpenseInputRules;
use Ui\Http\Expenses\ExpenseResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager enters an expense by hand in the organization selected by the
 * X-Organization-Id header (#54).
 *
 * @implements ProcessorInterface<EnterExpenseInput, ExpenseResource>
 */
final readonly class EnterExpenseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param EnterExpenseInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new EnterPurchaseInvoiceCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            invoiceId: $invoiceId,
            invoiceNumber: $data->invoiceNumber,
            sellerTaxId: $data->sellerTaxId,
            sellerName: $data->sellerName,
            issueDate: $data->issueDate,
            kind: $data->kind,
            currency: $data->currency,
            netTotal: $data->netTotal,
            vatTotal: $data->vatTotal,
            grossTotal: $data->grossTotal,
            vatLines: ExpenseInputRules::vatLines($data->vatLines),
        ));

        /** @var PurchaseInvoiceView $view */
        $view = $this->queryBus->ask(new GetPurchaseInvoiceQuery($organizationId, $actorId, $invoiceId));

        return ExpenseResource::fromView($view);
    }
}
