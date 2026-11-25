<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\GetPurchaseAnalysisQuery;
use App\Purchasing\Application\Query\GetPurchaseAnalysis\PurchaseAnalysisView;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Purchasing\PurchaseAnalysisInput;
use Ui\Http\Purchasing\PurchaseAnalysisResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The purchase analysis of the organization selected by the X-Organization-Id header for ?from=&to=,
 * after validating them as a PurchaseAnalysisInput (422). Employees are refused by the domain
 * (PurchasingAccess).
 *
 * @implements ProviderInterface<PurchaseAnalysisResource>
 */
final readonly class PurchaseAnalysisProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseAnalysisResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $input = new PurchaseAnalysisInput(
            from: self::stringParameter($operation, 'from'),
            to: self::stringParameter($operation, 'to'),
        );
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var PurchaseAnalysisView|null $view */
        $view = $this->queryBus->ask(new GetPurchaseAnalysisQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $input->from,
            (string) $input->to,
        ));

        return PurchaseAnalysisResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }

    /** The parameter's value, or null when it is missing or not a single string (e.g. `from[]=`). */
    private static function stringParameter(Operation $operation, string $name): ?string
    {
        $value = $operation->getParameters()?->get($name, QueryParameter::class)?->getValue();

        return \is_string($value) ? $value : null;
    }
}
