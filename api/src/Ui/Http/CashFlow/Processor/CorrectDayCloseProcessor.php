<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\CorrectDayClose\CorrectDayCloseCommand;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\GetDayClose\GetDayCloseQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashMovementLines;
use Ui\Http\CashFlow\CorrectDayCloseInput;
use Ui\Http\CashFlow\DayClosePaymentInput;
use Ui\Http\CashFlow\DayCloseResource;
use Ui\Http\CashFlow\DayCloseStorageInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager corrects an end-of-day close of the organization selected by the
 * X-Organization-Id header (ADR 0033 §7).
 *
 * @implements ProcessorInterface<CorrectDayCloseInput, DayCloseResource>
 */
final readonly class CorrectDayCloseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param CorrectDayCloseInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DayCloseResource
    {
        $dayCloseId = $uriVariables['id'];
        \assert(\is_string($dayCloseId));
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        $this->commandBus->handle(new CorrectDayCloseCommand(
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
        ));

        /** @var DayCloseView $view */
        $view = $this->queryBus->ask(new GetDayCloseQuery($organizationId, $actorId, $dayCloseId));

        return DayCloseResource::fromView($view);
    }
}
