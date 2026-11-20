<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Expenses\Application\Query\GetPurchaseInvoice\GetPurchaseInvoiceQuery;
use App\Expenses\Application\Query\GetPurchaseInvoice\PurchaseInvoiceView;
use App\Expenses\Domain\Exception\PurchaseInvoiceNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Expenses\ExpenseResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<ExpenseResource> */
final readonly class ExpenseProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $invoiceId = $uriVariables['id'];
        \assert(\is_string($invoiceId));

        /** @var PurchaseInvoiceView|null $view */
        $view = $this->queryBus->ask(new GetPurchaseInvoiceQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $invoiceId,
        ));

        return ExpenseResource::fromView($view ?? throw PurchaseInvoiceNotFoundException::withId($invoiceId));
    }
}
