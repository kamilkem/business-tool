<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\ReopenRevenueMonth\ReopenRevenueMonthCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;

use Ui\Http\Income\RevenueMonthReader;
use Ui\Http\Income\RevenueMonthResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<null, RevenueMonthResource> */
final readonly class ReopenRevenueMonthProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private RevenueMonthReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RevenueMonthResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $month = $uriVariables['month'];
        \assert(\is_string($month));

        $this->commandBus->handle(new ReopenRevenueMonthCommand($organizationId, $actorId, $month));

        return $this->reader->read($organizationId, $actorId, $month);
    }
}
