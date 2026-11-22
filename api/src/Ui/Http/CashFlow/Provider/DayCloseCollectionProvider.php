<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\ListDayCloses\ListDayClosesQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\CashFlow\DayCloseResource;
use Ui\Http\CashFlow\ListDayClosesInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the end-of-day closes of the organization selected by the X-Organization-Id header for
 * ?from=&to=, after validating them as a ListDayClosesInput (422).
 *
 * @implements ProviderInterface<DayCloseResource>
 */
final readonly class DayCloseCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<DayCloseResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListDayClosesInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<DayCloseView>|null $views */
        $views = $this->queryBus->ask(new ListDayClosesQuery($organizationId, $this->currentUser->id(), (string) $input->from, (string) $input->to));

        return array_map(DayCloseResource::fromView(...), $views ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
