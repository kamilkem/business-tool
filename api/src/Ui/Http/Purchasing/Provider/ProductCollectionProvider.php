<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Application\Query\ListProducts\ListProductsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Purchasing\ListProductsInput;
use Ui\Http\Purchasing\ProductResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The products of the organization selected by the X-Organization-Id header, for
 * [?categoryId=][&search=], after validating them as a ListProductsInput (422). Employees are
 * refused by the domain (PurchasingAccess).
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<ProductResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListProductsInput(
            categoryId: self::stringParameter($operation, 'categoryId'),
            search: self::stringParameter($operation, 'search'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<ProductView>|null $views */
        $views = $this->queryBus->ask(new ListProductsQuery($organizationId, $this->currentUser->id(), $input->categoryId, $input->search));

        return array_map(
            ProductResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `search[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
