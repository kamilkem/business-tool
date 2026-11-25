<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\ListPurchasedItems\ListPurchasedItemsQuery;
use App\Purchasing\Application\Query\ListPurchasedItems\PurchasedItemPageView;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Purchasing\ListPurchasedItemsInput;
use Ui\Http\Purchasing\PurchasedItemListResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * A page of the purchased items of the organization selected by the X-Organization-Id header for
 * [?status=][&productId=][&from=][&to=][&search=][&page=][&itemsPerPage=], after validating them as a
 * ListPurchasedItemsInput (422). Employees are refused by the domain (PurchasingAccess).
 *
 * @implements ProviderInterface<PurchasedItemListResource>
 */
final readonly class PurchasedItemListProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchasedItemListResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListPurchasedItemsInput(
            status: self::statusParameter($operation),
            productId: self::stringParameter($operation, 'productId'),
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
            search: self::stringParameter($operation, 'search'),
            page: self::stringParameter($operation, 'page'),
            itemsPerPage: self::stringParameter($operation, 'itemsPerPage'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var PurchasedItemPageView|null $view */
        $view = $this->queryBus->ask(new ListPurchasedItemsQuery(
            $organizationId,
            $this->currentUser->id(),
            $input->status,
            $input->productId,
            $input->from,
            $input->to,
            $input->search,
            null === $input->page ? 1 : (int) $input->page,
            null === $input->itemsPerPage ? ListPurchasedItemsQuery::DEFAULT_PAGE_SIZE : (int) $input->itemsPerPage,
        ));

        return PurchasedItemListResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /**
     * The states, from `status=a,b` or `status[]=a&status[]=b`; a value that is neither is left for
     * the validation to refuse.
     *
     * @return list<string>
     */
    private static function statusParameter(Operation $operation): array
    {
        $value = $operation->getParameters()?->get('status', QueryParameter::class)?->getValue();
        $values = \is_array($value) ? $value : (\is_string($value) ? explode(',', $value) : []);

        return array_values(array_map(static fn (mixed $status): string => \is_string($status) ? trim($status) : '', array_filter($values, static fn (mixed $status): bool => '' !== $status)));
    }

    /** The parameter's value, or null when it is missing, empty or not a single string (e.g. `search[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
