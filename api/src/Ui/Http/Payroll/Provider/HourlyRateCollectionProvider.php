<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetHourlyRate\HourlyRateView;
use App\Payroll\Application\Query\ListHourlyRates\ListHourlyRatesQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Payroll\HourlyRateResource;
use Ui\Http\Payroll\ListRatesInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Lists the hourly rates of the organization selected by the X-Organization-Id header, optionally for
 * ?userId= (validated as a ListRatesInput, 422). Who sees whose is decided by the handler.
 *
 * @implements ProviderInterface<HourlyRateResource>
 */
final readonly class HourlyRateCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<HourlyRateResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $userId = $operation->getParameters()?->get('userId', QueryParameter::class)?->getValue();
        $input = new ListRatesInput(\is_string($userId) ? $userId : null);
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<HourlyRateView>|null $views */
        $views = $this->queryBus->ask(new ListHourlyRatesQuery($organizationId, $this->currentUser->id(), $input->userId));

        return array_map(
            HourlyRateResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
