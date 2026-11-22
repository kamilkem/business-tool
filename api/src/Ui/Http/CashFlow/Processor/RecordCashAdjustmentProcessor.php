<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentCommand;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Application\Query\GetCashMovement\GetCashMovementQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\CashFlow\CashMovementLines;
use Ui\Http\CashFlow\CashMovementResource;
use Ui\Http\CashFlow\RecordCashAdjustmentInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds cash to or takes cash out of a storage in the organization selected by the X-Organization-Id header (ADR 0033 §4).
 *
 * @implements ProcessorInterface<RecordCashAdjustmentInput, CashMovementResource>
 */
final readonly class RecordCashAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param RecordCashAdjustmentInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CashMovementResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $movementId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new RecordCashAdjustmentCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            movementId: $movementId,
            storageId: $data->storageId,
            lines: CashMovementLines::toCommand($data->lines),
            note: $data->note,
            occurredAt: $data->occurredAt,
        ));

        /** @var CashMovementView $view */
        $view = $this->queryBus->ask(new GetCashMovementQuery($organizationId, $actorId, $movementId));

        return CashMovementResource::fromView($view);
    }
}
