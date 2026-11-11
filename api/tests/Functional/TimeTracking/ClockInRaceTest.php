<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Unit\TimeTracking\Application\MembershipQueryBus;
use App\TimeTracking\Application\Command\ClockIn\ClockInCommand;
use App\TimeTracking\Application\Command\ClockIn\ClockInHandler;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Two clock-ins of one person racing: both read "no open shift" before either commits. The second
 * insert hits the partial unique index, and the handler reports it as ShiftAlreadyOpenException
 * (409), not as a database error (500).
 */
#[CoversClass(ClockInHandler::class)]
final class ClockInRaceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function test_it_reports_a_concurrent_clock_in_as_already_clocked_in(): void
    {
        $organization = OrganizationFactory::createOne();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(TenantContextInterface::class)->switchTo(
            $entityManager->getReference(Organization::class, $organization->getId()) ?? throw new \LogicException(),
        );
        $shifts = self::getContainer()->get(ShiftRepositoryInterface::class);
        $userId = Uuid::v7()->toRfc4122();
        $organizationId = $organization->getId()->toRfc4122();
        $handler = new ClockInHandler(
            self::staleReads($shifts),
            MembershipQueryBus::of($organizationId, [$userId]),
            new MockClock('2026-11-11 16:00:00'),
        );
        $handler(new ClockInCommand($organizationId, Uuid::v7()->toRfc4122(), $userId));

        $this->expectException(ShiftAlreadyOpenException::class);

        $handler(new ClockInCommand($organizationId, Uuid::v7()->toRfc4122(), $userId));
    }

    /** The real repository, except that findOpenByUser() has not seen the other request's shift yet. */
    private static function staleReads(ShiftRepositoryInterface $shifts): ShiftRepositoryInterface
    {
        return new readonly class($shifts) implements ShiftRepositoryInterface {
            public function __construct(private ShiftRepositoryInterface $inner)
            {
            }

            public function findById(Uuid $id): ?Shift
            {
                return $this->inner->findById($id);
            }

            public function findOpenByUser(Uuid $userId): ?Shift
            {
                return null;
            }

            public function add(Shift $shift): void
            {
                $this->inner->add($shift);
            }

            public function save(Shift $shift): void
            {
                $this->inner->save($shift);
            }
        };
    }
}
