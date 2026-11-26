<?php

declare(strict_types=1);

namespace App\Tests\Api\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Model\MatchingRunOutcome;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Purchasing\Domain\Matching\ModelOutcomeCounts;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\InMemoryItemInterpreter;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * GET and POST /purchased-items/match: what waits for the model, what its last run did, and "Match again"
 * (ADR 0046). Nothing here reaches the model; the jobs queue is in memory and is not consumed.
 */
final class MatchingApiTest extends ApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_reports_the_waiting_items_the_model_and_no_run_yet(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        self::addItems($organization, 3);

        $status = self::clientFor($user, $organization)->request('GET', '/api/purchased-items/match')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['waitingCount' => 3, 'modelEnabled' => true, 'lastRun' => null], $status);
    }

    public function test_it_says_when_the_model_is_switched_off(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        static::getContainer()->get(InMemoryItemInterpreter::class)->available = false;

        $status = self::clientFor($owner, $organization)->request('GET', '/api/purchased-items/match')->toArray();

        self::assertFalse($status['modelEnabled']);
    }

    public function test_it_reports_the_last_run_with_what_the_model_did(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addRun($organization, '2026-11-26 09:00:00', MatchingRunOutcome::Done, new ModelOutcomeCounts(5, 1, 1));
        $newest = self::addRun($organization, '2026-11-26 10:00:00', MatchingRunOutcome::ModelUnavailable, new ModelOutcomeCounts(40, 25, 9));

        $status = self::clientFor($owner, $organization)->request('GET', '/api/purchased-items/match')->toArray();

        self::assertSame([
            'id' => $newest,
            'state' => 'model_unavailable',
            'startedAt' => '2026-11-26T10:00:00+00:00',
            'finishedAt' => '2026-11-26T10:05:00+00:00',
            'interpreted' => 40,
            'assigned' => 25,
            'suggested' => 9,
            'foundNothing' => 6,
        ], $status['lastRun']);
    }

    public function test_it_reports_a_run_that_has_not_ended_as_running_or_interrupted(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime('2026-11-26 12:00:00');
        self::addRun($organization, '2026-11-26 11:30:00', null, new ModelOutcomeCounts(0, 0, 0));

        self::assertSame(['running', null], self::runState($owner, $organization));

        self::mockTime('2026-11-26 13:00:01');

        self::assertSame(['interrupted', null], self::runState($owner, $organization));
    }

    public function test_it_shows_nothing_of_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        self::addItems($other, 4);
        self::addRun($other, '2026-11-26 10:00:00', MatchingRunOutcome::Done, new ModelOutcomeCounts(9, 9, 0));

        $status = self::clientFor($owner, $organization)->request('GET', '/api/purchased-items/match')->toArray();

        self::assertSame(['waitingCount' => 0, 'modelEnabled' => true, 'lastRun' => null], $status);
    }

    #[DataProvider('managingRoles')]
    public function test_it_queues_match_again_and_answers_202_with_the_status(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        self::addItems($organization, 2);
        self::mockTime('2026-11-26 12:00:00');

        $status = self::clientFor($user, $organization)->request('POST', '/api/purchased-items/match', ['json' => []])->toArray();

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['waitingCount' => 2, 'modelEnabled' => true, 'lastRun' => null], $status);
        $queued = self::queuedJobs();
        self::assertCount(1, $queued);
        $job = $queued[0]->getMessage();
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $job);
        self::assertSame([$organization->getId()->toRfc4122(), MatchScope::Unassigned->value, true], [$job->organizationId, $job->scope, $job->useInterpreter]);
        self::assertNotNull($job->askedBefore);
        self::assertEquals(new \DateTimeImmutable('2026-11-26 12:00:00'), new \DateTimeImmutable($job->askedBefore));
        self::assertSame('purchasing-match-items:'.$organization->getId()->toRfc4122().':unassigned:model', (string) $queued[0]->last(DeduplicateStamp::class)?->getKey());
    }

    public function test_it_does_not_queue_a_second_run_while_one_is_queued(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $client = self::clientFor($owner, $organization);

        // Each request has its own in-memory queue; the deduplication lock is in the database (ADR 0025 §5).
        $client->request('POST', '/api/purchased-items/match', ['json' => []]);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, self::queuedJobs());
        $client->request('POST', '/api/purchased-items/match', ['json' => []]);
        self::assertResponseStatusCodeSame(202);

        self::assertSame([], self::queuedJobs(), 'The first one still holds the key.');
    }

    public function test_it_queues_it_for_the_selected_organization_only(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $other = OrganizationFactory::createOne();
        self::addItems($other, 2);

        self::clientFor($owner, $organization)->request('POST', '/api/purchased-items/match', ['json' => []]);

        $queued = self::queuedJobs();
        self::assertCount(1, $queued);
        self::assertInstanceOf(MatchPurchasedItemsJob::class, $queued[0]->getMessage());
        self::assertSame($organization->getId()->toRfc4122(), $queued[0]->getMessage()->organizationId);
    }

    public function test_it_refuses_an_employee_both_reading_and_asking(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client = self::clientFor($employee, $organization);

        $client->request('GET', '/api/purchased-items/match');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/purchased-items/match', ['json' => []]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], self::queuedJobs());
    }

    public function test_it_refuses_someone_who_is_not_a_member_and_a_missing_login(): void
    {
        $organization = OrganizationFactory::createOne();
        $stranger = UserFactory::createOne();

        self::clientFor($stranger, $organization)->request('POST', '/api/purchased-items/match', ['json' => []]);
        self::assertResponseStatusCodeSame(403);
        static::createClient()->request('GET', '/api/purchased-items/match', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(401);

        self::assertSame([], self::queuedJobs());
    }

    // ---- Arranging ----

    /** @return array{string, ?string} */
    private static function runState(\App\User\Domain\Model\User $user, Organization $organization): array
    {
        $run = self::clientFor($user, $organization)->request('GET', '/api/purchased-items/match')->toArray()['lastRun'];

        return [$run['state'], $run['finishedAt']];
    }

    private static function addRun(Organization $organization, string $startedAt, ?MatchingRunOutcome $outcome, ModelOutcomeCounts $counts): string
    {
        $id = Uuid::v7();
        self::inTenant($organization, static function () use ($id, $startedAt, $outcome, $counts): void {
            $run = new MatchingRun($id, MatchScope::Unassigned, new \DateTimeImmutable($startedAt));
            if (null !== $outcome) {
                $run->finish($outcome, $counts, (new \DateTimeImmutable($startedAt))->modify('+5 minutes'));
            }
            static::getContainer()->get(MatchingRunRepositoryInterface::class)->add($run);
        });

        return $id->toRfc4122();
    }

    private static function addItems(Organization $organization, int $count): void
    {
        self::inTenant($organization, static function () use ($count): void {
            $items = [];
            for ($i = 1; $i <= $count; ++$i) {
                $items[] = PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
                    Uuid::v7(), $i, BusinessDate::fromString('2026-11-10'), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów',
                    1, 'Produkt '.$i, null, null, 'szt.', '6', '10', '12.3', null, 1_000, 1_230, 230, '23', false,
                ), Clock::get()->now());
            }
            static::getContainer()->get(PurchasedItemRepositoryInterface::class)->addAll($items);
        });
    }

    /** @return list<Envelope> what the last request queued on the in-memory `jobs` transport (ADR 0025 §7) */
    private static function queuedJobs(): array
    {
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }

    private static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }
    }
}
