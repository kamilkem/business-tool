<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseSettings;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Model\CashFlowAccess;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetDayCloseSettingsHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayCloseSettingsRepositoryInterface $settings,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws CashFlowAccessForbiddenException when the query is to manage and the actor is an employee */
    public function __invoke(GetDayCloseSettingsQuery $query): ?DayCloseSettingsView
    {
        $actor = CashFlowActor::memberOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }
        if ($query->toManage) {
            CashFlowAccess::assertCanManage($actor->id, $actor->role);
        }

        $settings = $this->settings->findCurrent();
        $rule = $settings?->rule() ?? DayCloseRule::default();

        return new DayCloseSettingsView($rule->mode->value, $rule->dayEndsAt->toString(), $settings?->getUpdatedAt());
    }
}
