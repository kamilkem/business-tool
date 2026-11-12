<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\ChangeRevenueEntry\ChangeRevenueEntryCommand;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\RevenueEntryLines;
use Ui\Http\Income\RevenueEntryResource;
use Ui\Http\Income\UpdateRevenueEntryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager corrects an entry's lines and note in the organization selected by the
 * X-Organization-Id header (ADR 0022 §8).
 *
 * @implements ProcessorInterface<UpdateRevenueEntryInput, RevenueEntryResource>
 */
final readonly class UpdateRevenueEntryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateRevenueEntryInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RevenueEntryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $entryId = $uriVariables['id'];
        \assert(\is_string($entryId) && null !== $data->payments);

        $this->commandBus->handle(new ChangeRevenueEntryCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            entryId: $entryId,
            payments: RevenueEntryLines::payments($data->payments),
            vatLines: RevenueEntryLines::vat($data->vatLines ?? []),
            note: $data->note,
        ));

        /** @var RevenueEntryView $view */
        $view = $this->queryBus->ask(new GetRevenueEntryQuery($organizationId, $actorId, $entryId));

        return RevenueEntryResource::fromView($view);
    }
}
