<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Application\Query\ListCashMovements\ListCashMovementsQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\CashFlow\CashMovementResource;
use Ui\Http\CashFlow\ListCashMovementsInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the cash movements of the organization selected by the X-Organization-Id header for
 * ?from=&to=[&storageId=], after validating them as a ListCashMovementsInput (422).
 *
 * @implements ProviderInterface<CashMovementResource>
 */
final readonly class CashMovementCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<CashMovementResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListCashMovementsInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
            storageId: self::stringParameter($operation, 'storageId'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<CashMovementView>|null $views */
        $views = $this->queryBus->ask(new ListCashMovementsQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
            $input->storageId,
        ));

        return array_map(
            CashMovementResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
