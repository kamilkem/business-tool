<?php

declare(strict_types=1);

namespace App\Tests\Api\Ksef;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\Ksef\InMemoryKsefClient;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST /ksef-connection/import, "Import now" (#52): it queues the organization's ImportKsefInvoicesJob
 * on the `jobs` transport (in memory in tests, never consumed; adr/0025 §7) and answers 202. The
 * clients keep one kernel, which resets the in-memory transport before each request: queuedJobs()
 * shows what the last request queued. Organizations are in Poland with a
 * valid NIP and an accepted token unless a test says otherwise; time is mocked at NOW.
 */
final class KsefImportApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-20 10:00:00';
    private const string NIP = '1111111111';
    private const string TOKEN = 'fake-ksef-token|nip-1111111111|not-a-real-token';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managers(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managers')]
    public function test_it_queues_the_import_with_its_deduplication_key_and_answers_202(MembershipRole $role): void
    {
        [$owner, $organization] = $this->connectedOrganization();
        $member = MembershipRole::Owner === $role ? $owner : $this->addMember($organization, $role);

        $response = $this->importNow($member, $organization);

        self::assertResponseStatusCodeSame(202);
        $body = $response->toArray();
        self::assertSame('2026-11-20T10:00:00+00:00', $body['lastImportRequestedAt']);
        self::assertSame('active', $body['status']);
        self::assertNull($body['syncRetryNotBefore']);

        $organizationId = $organization->getId()->toRfc4122();
        $queued = $this->queuedJobs();
        self::assertCount(1, $queued);
        self::assertEquals(new ImportKsefInvoicesJob($organizationId), $queued[0]->getMessage());
        self::assertSame('ksef-import:'.$organizationId, (string) $queued[0]->last(DeduplicateStamp::class)?->getKey());
        self::assertSame([], self::ksef()->downloaded, 'The request only queues the import.');
    }

    public function test_it_accepts_another_request_only_after_15_minutes(): void
    {
        [$owner, $organization] = $this->connectedOrganization();
        $this->importNow($owner, $organization);
        [$first] = $this->queuedJobs();

        self::mockTime('2026-11-20 10:14:00');
        $this->importNow($owner, $organization);
        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->queuedJobs());

        // The first job ran meanwhile and released its deduplication lock.
        $this->releaseDeduplicationLock($first);
        self::mockTime('2026-11-20 10:15:00');
        $this->importNow($owner, $organization);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $this->queuedJobs());
    }

    public function test_it_does_not_queue_a_second_import_while_one_is_queued(): void
    {
        [$owner, $organization] = $this->connectedOrganization();
        $this->importNow($owner, $organization);
        self::assertCount(1, $this->queuedJobs());
        self::mockTime('2026-11-20 10:30:00');

        $this->importNow($owner, $organization);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->queuedJobs(), 'The first job holds the deduplication lock until it ran.');
    }

    public function test_it_refuses_employees(): void
    {
        [, $organization] = $this->connectedOrganization();
        $employee = $this->addMember($organization, MembershipRole::Employee);

        $this->importNow($employee, $organization);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->queuedJobs());
    }

    public function test_it_answers_404_without_a_connection(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $this->changeSettings($organization, self::NIP, 'PL');
        self::mockTime(self::NOW);

        $this->importNow($owner, $organization);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_answers_409_when_the_token_was_removed_or_rejected(): void
    {
        [$owner, $organization] = $this->connectedOrganization('a-token-ksef-refuses');

        $this->importNow($owner, $organization);
        self::assertResponseStatusCodeSame(409);

        $this->request($owner, $organization, 'DELETE', '/api/ksef-connection/token');
        $this->importNow($owner, $organization);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->queuedJobs());
    }

    public function test_it_answers_422_outside_poland(): void
    {
        [$owner, $organization] = $this->connectedOrganization();
        $this->changeSettings($organization, 'DE123456789', 'DE');

        $this->importNow($owner, $organization);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedJobs());
    }

    public function test_it_never_lets_another_organization_start_or_see_the_import(): void
    {
        [$owner, $organization] = $this->connectedOrganization();
        [$otherOwner, $otherOrganization] = self::memberWithRole(MembershipRole::Owner);
        $this->changeSettings($otherOrganization, '2222222222', 'PL');

        // Not a member of the organization: the header is refused.
        $this->importNow($otherOwner, $organization);
        self::assertResponseStatusCodeSame(403);

        // In their own organization, which has no connection: nothing of the other one is visible.
        $this->importNow($otherOwner, $otherOrganization);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->queuedJobs());

        $this->importNow($owner, $organization);
        self::assertResponseStatusCodeSame(202);
        self::assertEquals([new ImportKsefInvoicesJob($organization->getId()->toRfc4122())], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->queuedJobs()));
    }

    /** @return array{User, Organization} an owner and their organization, with a saved token */
    private function connectedOrganization(string $token = self::TOKEN): array
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $this->changeSettings($organization, self::NIP, 'PL');
        self::mockTime(self::NOW);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $this->request($owner, $organization, 'POST', '/api/ksef-connection/token', ['token' => $token]);
        self::assertResponseStatusCodeSame(200);

        return [$owner, $organization];
    }

    private function addMember(Organization $organization, MembershipRole $role): User
    {
        $member = UserFactory::createOne();
        OrganizationMother::addMember($organization, $member, $role);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $member;
    }

    private function changeSettings(Organization $organization, ?string $taxId, string $country): void
    {
        $organization->changeSettings(OrganizationMother::ownerOf($organization)->getId(), $organization->getName(), $taxId, $country, 'PLN', 'Europe/Warsaw');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function importNow(User $user, Organization $organization): ResponseInterface
    {
        return $this->request($user, $organization, 'POST', '/api/ksef-connection/import');
    }

    /** What the worker does after handling the job (Messenger's DeduplicateMiddleware). */
    private function releaseDeduplicationLock(Envelope $job): void
    {
        self::getContainer()->get(LockFactory::class)->createLockFromKey($job->last(DeduplicateStamp::class)?->getKey() ?? self::fail('Deduplication stamp expected.'))->release();
    }

    /** @return list<Envelope> */
    private function queuedJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }

    private static function ksef(): InMemoryKsefClient
    {
        $ksef = self::testService(InMemoryKsefClient::class);
        self::assertInstanceOf(InMemoryKsefClient::class, $ksef);

        return $ksef;
    }

    /** A service registered for the test env only, which static analysis (dev container) does not know. */
    private static function testService(string $id): object
    {
        return self::getContainer()->get($id);
    }

    /** @param array<string, mixed>|null $body */
    private function request(User $user, Organization $organization, string $method, string $url, ?array $body = null): ResponseInterface
    {
        $client = self::clientFor($user, $organization);
        $client->disableReboot();
        $response = $client->request($method, $url, null === $body ? [] : ['json' => $body]);
        self::assertStringNotContainsString('not-a-real-token', $response->getContent(false));

        return $response;
    }
}
