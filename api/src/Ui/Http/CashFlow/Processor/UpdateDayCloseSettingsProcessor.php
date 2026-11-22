<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\ChangeDayCloseSettings\ChangeDayCloseSettingsCommand;
use App\CashFlow\Application\Query\GetDayCloseSettings\DayCloseSettingsView;
use App\CashFlow\Application\Query\GetDayCloseSettings\GetDayCloseSettingsQuery;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\CashFlow\DayCloseSettingsResource;
use Ui\Http\CashFlow\UpdateDayCloseSettingsInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager sets how the end-of-day closes of the organization selected by the
 * X-Organization-Id header get their date (ADR 0033 §5).
 *
 * @implements ProcessorInterface<UpdateDayCloseSettingsInput, DayCloseSettingsResource>
 */
final readonly class UpdateDayCloseSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdateDayCloseSettingsInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DayCloseSettingsResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        // The id is used only for the organization's first settings.
        $this->commandBus->handle(new ChangeDayCloseSettingsCommand($organizationId, $actorId, Uuid::v7()->toRfc4122(), $data->mode, $data->dayEndsAt));

        /** @var DayCloseSettingsView $view */
        $view = $this->queryBus->ask(new GetDayCloseSettingsQuery($organizationId, $actorId));

        return DayCloseSettingsResource::fromView($view);
    }
}
