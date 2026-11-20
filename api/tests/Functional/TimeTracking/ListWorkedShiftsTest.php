<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Factory\OrganizationFactory;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsHandler;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsQuery;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftsView;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftView;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\TimeTracking\Domain\Repository\ShiftChangeRequestRepositoryInterface;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The worked-shifts query as another module asks it, through the query bus against PostgreSQL: it
 * sees the current organization's shifts and requests only (TenantFilter).
 */
#[CoversClass(ListWorkedShiftsHandler::class)]
final class ListWorkedShiftsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string NOW = '2026-12-15T12:00:00+01:00';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
    }

    public function test_it_lists_the_current_organizations_shifts_and_pending_requests_only(): void
    {
        [$here, $elsewhere] = OrganizationFactory::createMany(2);
        $userId = Uuid::v7();

        $this->switchTo($elsewhere);
        $this->shift($userId, '2026-11-03T16:00:00+01:00', '2026-11-03T22:00:00+01:00');
        $this->missingShiftRequest($userId, '2026-11-04T16:00:00+01:00');

        $this->switchTo($here);
        $own = $this->shift($userId, '2026-11-05T16:00:00+01:00', '2026-11-06T01:00:00+01:00');
        $this->missingShiftRequest($userId, '2026-11-06T16:00:00+01:00');
        $this->entityManager->clear();

        self::assertEquals(new WorkedShiftsView([
            new WorkedShiftView(
                $own->getId()->toRfc4122(),
                $userId->toRfc4122(),
                '2026-11-05',
                new \DateTimeImmutable('2026-11-05T15:00:00Z'),
                new \DateTimeImmutable('2026-11-06T00:00:00Z'),
                9 * 3600,
            ),
        ], 1), $this->ask(new ListWorkedShiftsQuery('2026-11-01', '2026-11-30', 'Europe/Warsaw')));
    }

    private function ask(ListWorkedShiftsQuery $query): WorkedShiftsView
    {
        $view = self::getContainer()->get(QueryBusInterface::class)->ask($query);
        self::assertInstanceOf(WorkedShiftsView::class, $view);

        return $view;
    }

    private function shift(Uuid $userId, string $startedAt, string $endedAt): Shift
    {
        // The server's clock is in UTC; a clocked time is stored as it comes (ADR 0010).
        $shift = Shift::clockIn(Uuid::v7(), $userId, self::utc($startedAt), null);
        $shift->clockOut(self::utc($endedAt));
        self::getContainer()->get(ShiftRepositoryInterface::class)->add($shift);

        return $shift;
    }

    private function missingShiftRequest(Uuid $userId, string $startedAt): void
    {
        $start = new \DateTimeImmutable($startedAt);
        self::getContainer()->get(ShiftChangeRequestRepositoryInterface::class)->add(
            ShiftChangeRequest::forMissingShift(Uuid::v7(), $userId, $start, $start->modify('+6 hours'), 'Forgot to clock in', new \DateTimeImmutable(self::NOW)),
        );
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time)->setTimezone(new \DateTimeZone('UTC'));
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo(
            $this->entityManager->getReference(Organization::class, $organization->getId())
                ?? throw new \LogicException(),
        );
    }
}
