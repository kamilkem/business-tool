<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\ChangeDayCloseSettings;

use App\CashFlow\Application\CashFlowActor;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidDayEndTimeException;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Model\DayEndTime;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeDayCloseSettingsHandler implements CommandHandlerInterface
{
    public function __construct(
        private DayCloseSettingsRepositoryInterface $settings,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidDayEndTimeException       when the day-end time is not HH:MM from 00:00 to 12:00
     * @throws \ValueError                      when the mode is unknown (the input validates it)
     */
    public function __invoke(ChangeDayCloseSettingsCommand $command): void
    {
        $actor = CashFlowActor::managing($this->queryBus, $command->organizationId, $command->actorId);
        $rule = new DayCloseRule(DayCloseDateMode::from($command->mode), DayEndTime::fromString($command->dayEndsAt));
        $now = $this->clock->now();

        $settings = $this->settings->findCurrent();
        if (null === $settings) {
            $this->settings->add(DayCloseSettings::create(Uuid::fromString($command->settingsId), $rule, $actor->id, $actor->role, $now));

            return;
        }

        $settings->change($rule, $actor->id, $actor->role, $now);
        $this->settings->save($settings);
    }
}
