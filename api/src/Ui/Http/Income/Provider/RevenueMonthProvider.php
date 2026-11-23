<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use Ui\Http\Income\RevenueMonthReader;
use Ui\Http\Income\RevenueMonthResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<RevenueMonthResource> */
final readonly class RevenueMonthProvider implements ProviderInterface
{
    public function __construct(
        private RevenueMonthReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RevenueMonthResource
    {
        $month = $uriVariables['month'];
        \assert(\is_string($month));

        return $this->reader->read($this->tenantContext->getOrganization()->getId()->toRfc4122(), $this->currentUser->id(), $month);
    }
}
