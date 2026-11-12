<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Link\ClientApp;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\TimeTracking\Domain\Event\ShiftChangeRequestSubmitted;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tells every active owner and manager but the requester that a shift change request waits for their
 * decision, with a link to the admin panel (ADR 0012). Only one of them needs to decide it.
 */
final readonly class NotifyManagersOnShiftChangeRequestSubmitted implements EventHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private TransactionalMailerInterface $mailer,
        private AppLinks $links,
    ) {
    }

    /**
     * @throws ShiftChangeRequestNotFoundException
     * @throws OrganizationNotFoundException
     * @throws MemberNotFoundException
     */
    public function __invoke(ShiftChangeRequestSubmitted $event): void
    {
        $request = $this->requests->findById(Uuid::fromString($event->requestId))
            ?? throw ShiftChangeRequestNotFoundException::withId($event->requestId);

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($event->organizationId, $event->requesterId));
        $organization ??= throw OrganizationNotFoundException::forId($event->organizationId);

        /** @var MemberView|null $requester */
        $requester = $this->queryBus->ask(new GetMemberQuery($event->organizationId, $event->requesterId, $event->requesterId));
        $requester ??= throw MemberNotFoundException::of($event->requesterId, $event->organizationId);

        /** @var list<MemberView> $managers */
        $managers = $this->queryBus->ask(new ListManagingMembersQuery($event->organizationId)) ?? [];

        $shiftId = $request->getShiftId();
        $shift = null === $shiftId ? null : $this->shifts->findById($shiftId);
        $requesterName = trim($requester->firstName.' '.$requester->lastName);
        $context = ShiftChangeRequestEmail::context($request, $shift, $organization->timezone) + [
            'organizationName' => $organization->name,
            'requesterName' => $requesterName,
            'link' => $this->links->home(ClientApp::Admin),
        ];

        foreach ($managers as $manager) {
            if ($manager->userId === $requester->userId) {
                continue;
            }

            $this->mailer->send(new TransactionalEmail(
                to: $manager->email,
                subject: \sprintf('%s asks for a shift change', $requesterName),
                template: 'email/shift_change_request_submitted.html.twig',
                context: ['firstName' => $manager->firstName] + $context,
            ));
        }
    }
}
