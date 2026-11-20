<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetDayRate\DayRateView;
use App\Payroll\Application\Query\ListDayRates\ListDayRatesQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Payroll\DayRateResource;
use Ui\Http\Payroll\ListRatesInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the day rates of the organization selected by the X-Organization-Id header, optionally for
 * ?userId= (validated as a ListRatesInput, 422). Who sees whose is decided by the handler.
 *
 * @implements ProviderInterface<DayRateResource>
 */
final readonly class DayRateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<DayRateResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $userId = $operation->getParameters()?->get('userId', QueryParameter::class)?->getValue();
        $input = new ListRatesInput(\is_string($userId) ? $userId : null);
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<DayRateView>|null $views */
        $views = $this->queryBus->ask(new ListDayRatesQuery($organizationId, $this->currentUser->id(), $input->userId));

        return array_map(
            DayRateResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
