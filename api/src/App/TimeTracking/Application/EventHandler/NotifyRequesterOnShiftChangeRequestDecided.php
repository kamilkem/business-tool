<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Language\Language;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\TimeTracking\Domain\Event\ShiftChangeRequestDecided;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the requester that their shift change request was approved or rejected (ADR 0012), with the
 * reason if one was given. A deactivated requester is told too: the decision is about their pay.
 * Members are looked up as the decider, an active owner or manager who sees everyone.
 */
final readonly class NotifyRequesterOnShiftChangeRequestDecided implements EventHandlerInterface
{
    public function __construct(
        private ShiftChangeRequestRepositoryInterface $requests,
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
    public function __invoke(ShiftChangeRequestDecided $event): void
    {
        $request = $this->requests->findById(Uuid::fromString($event->requestId))
            ?? throw ShiftChangeRequestNotFoundException::withId($event->requestId);

        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($event->organizationId, $event->decidedById));
        $organization ??= throw OrganizationNotFoundException::forId($event->organizationId);

        /** @var MemberView|null $requester */
        $requester = $this->queryBus->ask(new GetMemberQuery($event->organizationId, $event->decidedById, $event->requesterId));
        $requester ??= throw MemberNotFoundException::of($event->requesterId, $event->organizationId);

        /** @var MemberView|null $decider */
        $decider = $this->queryBus->ask(new GetMemberQuery($event->organizationId, $event->decidedById, $event->decidedById));
        $decider ??= throw MemberNotFoundException::of($event->decidedById, $event->organizationId);

        $approved = ShiftChangeRequestStatus::Approved === $request->getStatus();
        $locale = TransactionalEmail::localeOf(null === $requester->language ? null : Language::from($requester->language));

        $this->mailer->send(new TransactionalEmail(
            to: $requester->email,
            locale: $locale,
            subject: $approved ? 'shift_change_request_decided.subject_approved' : 'shift_change_request_decided.subject_rejected',
            template: 'email/shift_change_request_decided.html.twig',
            context: ShiftChangeRequestEmail::context($request, null, $organization->timezone, $locale) + [
                'firstName' => $requester->firstName,
                'organizationName' => $organization->name,
                'approved' => $approved,
                'deciderName' => trim($decider->firstName.' '.$decider->lastName),
                'rejectionReason' => $request->getRejectionReason(),
                'link' => $this->links->home(AppLinks::appFor(MembershipRole::from($requester->role))),
            ],
        ));
    }
}
