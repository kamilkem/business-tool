<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Link\ClientApp;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\Shared\Domain\Language\Language;
use App\TimeTracking\Domain\Event\LongShiftReported;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the person whether they forgot to clock out, and tells every other active owner and manager
 * that the shift runs longer than the maximum shift length (#33, ADR 0012). A deactivated person is
 * not e-mailed; their managers still are. Nothing about the shift changes.
 */
final readonly class NotifyOnLongShiftReported implements EventHandlerInterface
{
    /** ICU pattern: "Thu 12 Nov 2026, 17:00" in English, "czw. 12 lis 2026, 17:00" in Polish. */
    private const string TIME_PATTERN = 'EEE d MMM y, HH:mm';

    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private QueryBusInterface $queryBus,
        private TransactionalMailerInterface $mailer,
        private AppLinks $links,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LongShiftReported $event): void
    {
        $shift = $this->shifts->findById(Uuid::fromString($event->shiftId));
        if (null === $shift) {
            return;
        }

        /** @var list<MemberView> $managers */
        $managers = $this->queryBus->ask(new ListManagingMembersQuery($event->organizationId)) ?? [];

        // The queries need an active member to ask as: the person, or else any manager.
        /** @var MemberView|null $person */
        $person = $this->queryBus->ask(new GetMemberQuery($event->organizationId, $event->userId, $event->userId));
        $actorId = null !== $person ? $event->userId : ($managers[0]->userId ?? null);
        if (null === $actorId) {
            return;
        }
        $person ??= $this->queryBus->ask(new GetMemberQuery($event->organizationId, $actorId, $event->userId));
        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($event->organizationId, $actorId));
        if (!$person instanceof MemberView || null === $organization) {
            return;
        }

        $hours = intdiv($this->clock->now()->getTimestamp() - $shift->getStartedAt()->getTimestamp(), 3600);
        $context = [
            'organizationName' => $organization->name,
            'hours' => $hours,
            'maxShiftHours' => $event->maxShiftHours,
            'timeZone' => $organization->timezone,
        ];

        if (null === $person->deactivatedAt) {
            $locale = self::localeOf($person);
            $this->mailer->send(new TransactionalEmail(
                to: $person->email,
                locale: $locale,
                subject: 'long_shift_employee.subject',
                template: 'email/long_shift_employee.html.twig',
                context: [
                    'firstName' => $person->firstName,
                    'startedAt' => self::format($shift->getStartedAt(), $organization->timezone, $locale),
                    'link' => $this->links->home(ClientApp::Timeclock),
                ] + $context,
            ));
        }

        $employeeName = trim($person->firstName.' '.$person->lastName);
        foreach ($managers as $manager) {
            if ($manager->userId === $person->userId) {
                continue;
            }

            $locale = self::localeOf($manager);
            $this->mailer->send(new TransactionalEmail(
                to: $manager->email,
                locale: $locale,
                subject: 'long_shift_manager.subject',
                template: 'email/long_shift_manager.html.twig',
                context: [
                    'firstName' => $manager->firstName,
                    'employeeName' => $employeeName,
                    'startedAt' => self::format($shift->getStartedAt(), $organization->timezone, $locale),
                    'link' => $this->links->adminShifts(),
                ] + $context,
                subjectParameters: ['employeeName' => $employeeName],
            ));
        }
    }

    private static function localeOf(MemberView $member): string
    {
        return TransactionalEmail::localeOf(null === $member->language ? null : Language::from($member->language));
    }

    private static function format(\DateTimeImmutable $time, string $timeZone, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timeZone, \IntlDateFormatter::GREGORIAN, self::TIME_PATTERN);
        $formatted = $formatter->format($time);
        if (false === $formatted) {
            throw new \RuntimeException($formatter->getErrorMessage());
        }

        return $formatted;
    }
}
