<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\GetMyPay\GetMyPayQuery;
use App\Payroll\Application\Query\GetMyPay\MyPayView;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ui\Http\Payroll\MyPayInput;
use Ui\Http\Payroll\MyPayResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The signed-in member's own pay in ?month= (validated as a MyPayInput, 422) in the organization
 * selected by the X-Organization-Id header. There is no way to ask for anyone else's.
 *
 * @implements ProviderInterface<MyPayResource>
 */
final readonly class MyPayProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
        private ValidatorInterface $validator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyPayResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $month = $operation->getParameters()?->get('month', QueryParameter::class)?->getValue();
        $input = new MyPayInput(\is_string($month) ? $month : null);
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        /** @var MyPayView|null $view */
        $view = $this->queryBus->ask(new GetMyPayQuery($organizationId, $this->currentUser->id(), $input->month));

        return MyPayResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
