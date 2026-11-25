<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetProductPriceHistory\GetProductPriceHistoryQuery;
use App\Purchasing\Application\Query\GetProductPriceHistory\ProductPriceHistoryView;
use App\Purchasing\Domain\Exception\ProductNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Purchasing\PriceHistoryInput;
use Ui\Http\Purchasing\ProductPriceHistoryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The price history of a product of the organization selected by the X-Organization-Id header for
 * [?from=][&to=], after validating them as a PriceHistoryInput (422). An employee is refused by the
 * domain (PurchasingAccess); another organization's product is a 404.
 *
 * @implements ProviderInterface<ProductPriceHistoryResource>
 */
final readonly class ProductPriceHistoryProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductPriceHistoryResource
    {
        $productId = $uriVariables['id'];
        \assert(\is_string($productId));

        $input = new PriceHistoryInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var ProductPriceHistoryView|null $view */
        $view = $this->queryBus->ask(new GetProductPriceHistoryQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $productId,
            $input->from,
            $input->to,
        ));

        return ProductPriceHistoryResource::fromView($view ?? throw ProductNotFoundException::withId($productId));
    }

    /** The parameter's value, or null when it is missing, empty or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
