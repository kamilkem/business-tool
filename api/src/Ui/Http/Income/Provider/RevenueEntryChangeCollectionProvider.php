<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Income\Application\Query\ListRevenueEntryChanges\ListRevenueEntryChangesQuery;
use App\Income\Application\Query\ListRevenueEntryChanges\RevenueEntryChangeView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Income\ListRevenueEntryChangesInput;
use Ui\Http\Income\RevenueEntryChangeResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<RevenueEntryChangeResource> */
final readonly class RevenueEntryChangeCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<RevenueEntryChangeResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListRevenueEntryChangesInput(
            entryId: self::stringParameter($operation, 'entryId'),
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<RevenueEntryChangeView>|null $views */
        $views = $this->queryBus->ask(new ListRevenueEntryChangesQuery($organizationId, $this->currentUser->id(), $input->entryId, $input->from, $input->to));

        return array_map(RevenueEntryChangeResource::fromView(...), $views ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
