<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<PayrollRunResource> */
final readonly class PayrollRunProvider implements ProviderInterface
{
    public function __construct(
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $runId = $uriVariables['id'];
        \assert(\is_string($runId));

        return $this->reader->read($this->tenantContext->getOrganization()->getId()->toRfc4122(), $this->currentUser->id(), $runId);
    }
}
