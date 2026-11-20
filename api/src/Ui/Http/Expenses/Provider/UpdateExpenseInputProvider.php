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
use Ui\Http\Expenses\UpdateExpenseInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the expense's current category; API Platform then applies
 * the request body onto it. An employee is refused here (403) and an expense that is not the
 * organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdateExpenseInput>
 */
final readonly class UpdateExpenseInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateExpenseInput
    {
        $invoiceId = $uriVariables['id'];
        \assert(\is_string($invoiceId));

        /** @var PurchaseInvoiceView|null $view */
        $view = $this->queryBus->ask(new GetPurchaseInvoiceQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $invoiceId,
        ));

        return UpdateExpenseInput::fromView($view ?? throw PurchaseInvoiceNotFoundException::withId($invoiceId));
    }
}
