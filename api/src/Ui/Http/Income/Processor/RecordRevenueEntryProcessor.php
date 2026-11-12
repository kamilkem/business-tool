<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\RecordRevenueEntry\RecordRevenueEntryCommand;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Income\RecordRevenueEntryInput;
use Ui\Http\Income\RevenueEntryLines;
use Ui\Http\Income\RevenueEntryResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager records a register's daily report in the organization selected by the
 * X-Organization-Id header (ADR 0022).
 *
 * @implements ProcessorInterface<RecordRevenueEntryInput, RevenueEntryResource>
 */
final readonly class RecordRevenueEntryProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param RecordRevenueEntryInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RevenueEntryResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $entryId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new RecordRevenueEntryCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            entryId: $entryId,
            registerId: $data->registerId,
            businessDate: $data->businessDate,
            payments: RevenueEntryLines::payments($data->payments),
            vatLines: RevenueEntryLines::vat($data->vatLines),
            note: $data->note,
        ));

        /** @var RevenueEntryView $view */
        $view = $this->queryBus->ask(new GetRevenueEntryQuery($organizationId, $actorId, $entryId));

        return RevenueEntryResource::fromView($view);
    }
}
