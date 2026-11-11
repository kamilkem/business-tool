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
use App\TimeTracking\Application\Query\ListShifts\ListShiftsQuery;
use App\TimeTracking\Application\Query\ListShifts\ShiftListItemView;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ListShiftsInput;
use Ui\Http\TimeTracking\ShiftListItemResource;

/**
 * Lists the shifts of the organization selected by the X-Organization-Id header (400 without it)
 * for ?from=&to=[&userId=], after validating them as a ListShiftsInput (422). Who sees whose shifts
 * is decided by the handler.
 *
 * @implements ProviderInterface<ShiftListItemResource>
 */
final readonly class ShiftCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<ShiftListItemResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new ListShiftsInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
            userId: self::stringParameter($operation, 'userId'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var list<ShiftListItemView>|null $views */
        $views = $this->queryBus->ask(new ListShiftsQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
            $input->userId,
        ));

        return array_map(
            ShiftListItemResource::fromView(...),
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
