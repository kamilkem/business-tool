<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\ListRevenueEntries\ListRevenueEntriesQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Income\ListRevenueEntriesInput;
use Ui\Http\Income\RevenueEntryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the revenue entries of the organization selected by the X-Organization-Id header for
 * ?from=&to=[&registerId=], after validating them as a ListRevenueEntriesInput (422).
 *
 * @implements ProviderInterface<RevenueEntryResource>
 */
final readonly class RevenueEntryCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<RevenueEntryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListRevenueEntriesInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
            registerId: self::stringParameter($operation, 'registerId'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<RevenueEntryView>|null $views */
        $views = $this->queryBus->ask(new ListRevenueEntriesQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
            $input->registerId,
        ));

        return array_map(
            RevenueEntryResource::fromView(...),
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
