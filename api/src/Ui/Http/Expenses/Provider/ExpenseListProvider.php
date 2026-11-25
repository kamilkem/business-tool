<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Expenses\Application\Query\ListPurchaseInvoices\ListPurchaseInvoicesQuery;
use App\Expenses\Application\Query\ListPurchaseInvoices\PurchaseInvoiceListView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Expenses\ExpenseListResource;
use Ui\Http\Expenses\ListExpensesInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the expenses of the organization selected by the X-Organization-Id header for
 * ?from=&to=[&seller=], after validating them as a ListExpensesInput (422).
 *
 * @implements ProviderInterface<ExpenseListResource>
 */
final readonly class ExpenseListProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseListResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListExpensesInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
            seller: self::stringParameter($operation, 'seller'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var PurchaseInvoiceListView|null $view */
        $view = $this->queryBus->ask(new ListPurchaseInvoicesQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
            $input->seller,
        ));

        return ExpenseListResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
