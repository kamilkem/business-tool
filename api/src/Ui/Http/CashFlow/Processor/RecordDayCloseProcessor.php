<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\RecordDayClose\RecordDayCloseCommand;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\GetDayCloseReceipt\GetDayCloseReceiptQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\CashFlow\CashMovementLines;
use Ui\Http\CashFlow\DayClosePaymentInput;
use Ui\Http\CashFlow\DayCloseReceiptResource;
use Ui\Http\CashFlow\DayCloseStorageInput;
use Ui\Http\CashFlow\RecordDayCloseInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Any active member closes a business date in the organization selected by the X-Organization-Id header (ADR 0033 §6).
 *
 * @implements ProcessorInterface<RecordDayCloseInput, DayCloseReceiptResource>
 */
final readonly class RecordDayCloseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param RecordDayCloseInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DayCloseReceiptResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $dayCloseId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new RecordDayCloseCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            dayCloseId: $dayCloseId,
            registerId: $data->registerId,
            storages: array_map(static fn (DayCloseStorageInput $storage): array => [
                'storageId' => $storage->storageId,
                'lines' => CashMovementLines::toCommand($storage->lines),
            ], $data->storages),
            payments: array_map(static fn (DayClosePaymentInput $payment): array => [
                'paymentMethodId' => $payment->paymentMethodId,
                'amount' => $payment->amount,
            ], $data->payments),
            date: $data->date,
        ));

        /** @var DayCloseView $view */
        $view = $this->queryBus->ask(new GetDayCloseReceiptQuery($organizationId, $actorId, $dayCloseId));

        return DayCloseReceiptResource::fromView($view);
    }
}
