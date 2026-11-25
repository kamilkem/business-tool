<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProductCategory\GetProductCategoryQuery;
use App\Purchasing\Application\Query\GetProductCategory\ProductCategoryView;
use App\Purchasing\Domain\Exception\ProductCategoryNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\UpdateProductCategoryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the category's current name and parent; API Platform then
 * applies the request body onto it. An employee is refused here (403) and a category that is not
 * the organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdateProductCategoryInput>
 */
final readonly class UpdateProductCategoryInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateProductCategoryInput
    {
        $categoryId = $uriVariables['id'];
        \assert(\is_string($categoryId));

        /** @var ProductCategoryView|null $view */
        $view = $this->queryBus->ask(new GetProductCategoryQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $categoryId,
        ));

        return UpdateProductCategoryInput::fromView($view ?? throw ProductCategoryNotFoundException::withId($categoryId));
    }
}
