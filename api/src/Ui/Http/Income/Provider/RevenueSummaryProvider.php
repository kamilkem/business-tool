<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Income\Application\Query\GetRevenueSummary\GetRevenueSummaryQuery;
use App\Income\Application\Query\GetRevenueSummary\RevenueSummaryView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Income\RevenueSummaryInput;
use Ui\Http\Income\RevenueSummaryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The revenue summary of the organization selected by the X-Organization-Id header for ?from=&to=,
 * after validating them as a RevenueSummaryInput (422).
 *
 * @implements ProviderInterface<RevenueSummaryResource>
 */
final readonly class RevenueSummaryProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RevenueSummaryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new RevenueSummaryInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var RevenueSummaryView|null $view */
        $view = $this->queryBus->ask(new GetRevenueSummaryQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
        ));

        return RevenueSummaryResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
