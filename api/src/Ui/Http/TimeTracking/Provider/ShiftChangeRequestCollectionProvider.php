<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use App\TimeTracking\Application\Query\ListShiftChangeRequests\ListShiftChangeRequestsQuery;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ListShiftChangeRequestsInput;
use Ui\Http\TimeTracking\ShiftChangeRequestResource;

/**
 * Lists the shift change requests of the organization selected by the X-Organization-Id header
 * (400 without it) for ?status=, after validating it as a ListShiftChangeRequestsInput (422). Who
 * sees whose requests is decided by the handler.
 *
 * @implements ProviderInterface<ShiftChangeRequestResource>
 */
final readonly class ShiftChangeRequestCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<ShiftChangeRequestResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $status = $operation->getParameters()?->get('status', QueryParameter::class)?->getValue();
        $input = new ListShiftChangeRequestsInput(\is_string($status) ? $status : null);
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<ShiftChangeRequestView>|null $views */
        $views = $this->queryBus->ask(new ListShiftChangeRequestsQuery($organizationId, $this->currentUser->id(), $input->status));

        return array_map(
            ShiftChangeRequestResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
