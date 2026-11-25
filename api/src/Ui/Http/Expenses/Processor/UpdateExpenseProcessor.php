<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\ChangePurchaseInvoice\ChangePurchaseInvoiceCommand;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceQuery;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseInputRules;
use Ui\Http\Expenses\ExpenseResource;
use Ui\Http\Expenses\UpdateExpenseInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager edits an expense of the organization selected by the X-Organization-Id
 * header: what a manual one says (#54).
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
        // The validator has checked these are set (NotNull, NotBlank).
        \assert(null !== $data->sellerName && null !== $data->issueDate && null !== $data->kind && null !== $data->currency);
        \assert(null !== $data->netTotal && null !== $data->vatTotal && null !== $data->grossTotal);

        $this->commandBus->handle(new ChangePurchaseInvoiceCommand(
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
            vatLines: ExpenseInputRules::vatLines($data->vatLines ?? []),
        ));

        /** @var PurchaseInvoiceView $view */
        $view = $this->queryBus->ask(new GetPurchaseInvoiceQuery($organizationId, $actorId, $invoiceId));

        return ExpenseResource::fromView($view);
    }
}
