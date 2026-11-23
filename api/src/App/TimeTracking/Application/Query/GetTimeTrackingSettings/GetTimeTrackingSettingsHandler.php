<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetTimeTrackingSettings;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Domain\Exception\TimeTrackingSettingsChangeForbiddenException;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use App\TimeTracking\Domain\Model\TimeTrackingSettings;
use App\TimeTracking\Domain\Repository\TimeTrackingSettingsRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetTimeTrackingSettingsHandler implements QueryHandlerInterface
{
    public function __construct(
        private TimeTrackingSettingsRepositoryInterface $settings,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws TimeTrackingSettingsChangeForbiddenException when the query is to manage and the actor is an employee */
    public function __invoke(GetTimeTrackingSettingsQuery $query): ?TimeTrackingSettingsView
    {
        /** @var MemberView|null $actor */
        $actor = $this->queryBus->ask(new GetMemberQuery($query->organizationId, $query->actorId, $query->actorId));
        if (null === $actor) {
            return null;
        }
        if ($query->toManage) {
            TimeTrackingSettings::assertCanChange(Uuid::fromString($actor->userId), MembershipRole::from($actor->role));
        }

        $settings = $this->settings->findCurrent();

        return new TimeTrackingSettingsView(
            ($settings?->maxShiftLength() ?? MaxShiftLength::default())->hours,
            $settings?->getUpdatedAt(),
        );
    }
}
