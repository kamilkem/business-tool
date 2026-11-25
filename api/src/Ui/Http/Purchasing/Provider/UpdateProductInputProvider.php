<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProduct\GetProductQuery;
use App\Purchasing\Application\Query\GetProduct\ProductView;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\UpdateProductInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the product's current values; API Platform then applies the
 * request body onto it. An employee is refused here (403) and a product that is not the
 * organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdateProductInput>
 */
final readonly class UpdateProductInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateProductInput
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        /** @var ProductView|null $view */
        $view = $this->queryBus->ask(new GetProductQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $productId,
        ));

        return UpdateProductInput::fromView($view ?? throw ProductNotFoundException::withId($productId));
    }
}
