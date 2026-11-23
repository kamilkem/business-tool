<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ChangeTimeTrackingSettings;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Exception\InvalidMaxShiftLengthException;
use App\TimeTracking\Domain\Exception\TimeTrackingSettingsChangeForbiddenException;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Model\TimeTrackingSettings;
use App\TimeTracking\Domain\Repository\TimeTrackingSettingsRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeTimeTrackingSettingsHandler implements CommandHandlerInterface
{
    public function __construct(
        private TimeTrackingSettingsRepositoryInterface $settings,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException                when the actor is not an active member of the organization
     * @throws TimeTrackingSettingsChangeForbiddenException when the actor is not an owner or manager
     * @throws InvalidMaxShiftLengthException               when the hours are out of range
     */
    public function __invoke(ChangeTimeTrackingSettingsCommand $command): void
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($command->organizationId, $command->actorId, $command->actorId));
        $actor ??= throw OrganizationNotFoundException::forId($command->organizationId);
        $actorId = Uuid::fromString($actor->userId);
        $actorRole = MembershipRole::from($actor->role);
        TimeTrackingSettings::assertCanChange($actorId, $actorRole);

        $maxShiftLength = MaxShiftLength::ofHours($command->maxShiftHours);
        $now = $this->clock->now();

        $settings = $this->settings->findCurrent();
        if (null === $settings) {
            $this->settings->add(TimeTrackingSettings::create(Uuid::fromString($command->settingsId), $maxShiftLength, $actorId, $actorRole, $now));

            return;
        }

        $settings->change($maxShiftLength, $actorId, $actorRole, $now);
        $this->settings->save($settings);
    }
}
