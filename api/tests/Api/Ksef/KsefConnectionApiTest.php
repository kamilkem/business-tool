<?php

declare(strict_types=1);

namespace App\Tests\Api\Ksef;

use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Fixtures\Ksef\InMemoryKsefClient;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /ksef-connection, POST and DELETE /ksef-connection/token, POST /ksef-connection/test (#49).
 * KSeF is the in-memory one (adr/0023 §8); the clients keep one kernel, so it keeps what it was told.
 * Organizations are in Poland with a valid NIP unless a test says otherwise; time is mocked at NOW.
 */
final class KsefConnectionApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string NIP = '1111111111';
    private const string TOKEN = 'fake-ksef-token|nip-1111111111|not-a-real-token';

    /** @var list<string> every response body of the test, to check that no token ever appears */
    private array $bodies = [];

    protected function tearDown(): void
    {
        foreach ($this->bodies as $body) {
            self::assertStringNotContainsString(self::TOKEN, $body);
            self::assertStringNotContainsString('not-a-real-token', $body);
        }

        parent::tearDown();
    }

    public function test_it_saves_an_owners_token_checks_it_and_never_returns_it(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);

        $saved = $this->saveToken($owner, $organization)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'id' => $saved['id'],
            'status' => 'active',
            'tokenSet' => true,
            'tokenSetAt' => '2026-11-13T10:00:00+00:00',
            'nip' => self::NIP,
            'verifiedAt' => '2026-11-13T10:00:00+00:00',
            'lastCheckedAt' => '2026-11-13T10:00:00+00:00',
            'lastCheckError' => null,
            'lastCheckErrorMessage' => null,
            'syncedUntil' => null,
            'lastSyncAt' => null,
            'lastSyncSucceededAt' => null,
            'lastSyncError' => null,
            'lastSyncErrorMessage' => null,
            'lastSyncImportedCount' => 0,
            'lastSyncSkippedCount' => 0,
            'createdAt' => '2026-11-13T10:00:00+00:00',
        ], $saved);
        self::assertSame(['in-memory-1'], self::ksef()->endedSessions);

        self::assertSame($saved, $this->request($owner, $organization, 'GET', '/api/ksef-connection')->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_stores_the_token_encrypted(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);

        $this->saveToken($owner, $organization);

        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT * FROM organization_secret');
        self::assertCount(1, $rows);
        self::assertSame('ksef_token', $rows[0]['kind']);
        self::assertSame('test-1', $rows[0]['key_id']);
        self::assertStringNotContainsString('not-a-real-token', (string) json_encode($rows));
        self::assertStringNotContainsString('not-a-real-token', (string) base64_decode((string) $rows[0]['ciphertext'], true));
    }

    public function test_it_saves_a_token_ksef_refuses_as_rejected(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);

        $saved = $this->saveToken($owner, $organization)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('credentials_rejected', $saved['status']);
        self::assertTrue($saved['tokenSet']);
        self::assertNull($saved['verifiedAt']);
        self::assertSame('credentials_rejected', $saved['lastCheckError']);
    }

    public function test_it_replaces_the_token_and_checks_the_new_one(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        $first = $this->saveToken($owner, $organization, 'an-old-fake-token')->toArray();
        self::assertSame('credentials_rejected', $first['status']);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        self::mockTime('2026-11-14 09:00:00');

        $second = $this->saveToken($owner, $organization)->toArray();

        self::assertSame($first['id'], $second['id']);
        self::assertSame('active', $second['status']);
        self::assertSame('2026-11-14T09:00:00+00:00', $second['tokenSetAt']);
        self::assertSame('2026-11-14T09:00:00+00:00', $second['verifiedAt']);
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM organization_secret'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTokens(): iterable
    {
        yield 'no token' => [[]];
        yield 'null' => [['token' => null]];
        yield 'empty' => [['token' => '']];
        yield 'blank' => [['token' => '   ']];
        yield 'too long' => [['token' => str_repeat('a', 1001)]];
        yield 'a number' => [['token' => 123]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidTokens')]
    public function test_it_answers_422_with_violations_for_an_invalid_token(array $body): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);

        $response = $this->request($owner, $organization, 'POST', '/api/ksef-connection/token', $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['token'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        $this->request($owner, $organization, 'GET', '/api/ksef-connection');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function organizationsWithoutKsef(): iterable
    {
        yield 'in Germany' => [self::NIP, 'DE'];
        yield 'no tax id' => [null, 'PL'];
        yield 'not a valid NIP' => ['1111111112', 'PL'];
    }

    #[DataProvider('organizationsWithoutKsef')]
    public function test_it_answers_422_where_ksef_is_not_available_and_stores_nothing(?string $taxId, string $country): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $this->changeSettings($organization, $taxId, $country);

        $response = $this->saveToken($owner, $organization);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('KSeF', $response->toArray(false)['detail'] ?? '');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM organization_secret'));
        $this->request($owner, $organization, 'GET', '/api/ksef-connection');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_answers_404_before_a_token_is_saved(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);

        foreach ([['GET', '/api/ksef-connection'], ['POST', '/api/ksef-connection/test'], ['DELETE', '/api/ksef-connection/token']] as [$method, $url]) {
            $this->request($owner, $organization, $method, $url);
            self::assertResponseStatusCodeSame(404, $method.' '.$url);
        }
    }

    public function test_it_removes_the_token_and_disables_the_connection(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $this->saveToken($owner, $organization);

        $this->request($owner, $organization, 'DELETE', '/api/ksef-connection/token');

        self::assertResponseStatusCodeSame(204);
        $connection = $this->request($owner, $organization, 'GET', '/api/ksef-connection')->toArray();
        self::assertSame('disabled', $connection['status']);
        self::assertFalse($connection['tokenSet']);
        self::assertNull($connection['tokenSetAt']);
        self::assertNull($connection['verifiedAt']);
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM organization_secret'));

        $this->request($owner, $organization, 'POST', '/api/ksef-connection/test');
        self::assertResponseStatusCodeSame(409);
        $this->request($owner, $organization, 'DELETE', '/api/ksef-connection/token');
        self::assertResponseStatusCodeSame(404);

        self::assertSame('active', $this->saveToken($owner, $organization)->toArray()['status']);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_see_and_test_the_connection(MembershipRole $role): void
    {
        [$actor, $organization] = $this->memberOfPolishOrganization($role);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $this->saveToken(OrganizationMother::ownerOf($organization), $organization);
        self::mockTime('2026-11-13 12:00:00');

        $this->request($actor, $organization, 'GET', '/api/ksef-connection');
        self::assertResponseStatusCodeSame(200);
        $tested = $this->request($actor, $organization, 'POST', '/api/ksef-connection/test')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $tested['status']);
        self::assertSame('2026-11-13T12:00:00+00:00', $tested['lastCheckedAt']);
        self::assertSame('2026-11-13T12:00:00+00:00', $tested['verifiedAt']);
    }

    public function test_it_reports_a_failed_test_in_the_body(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $this->saveToken($owner, $organization);
        self::ksef()->failNextCallWith(KsefUnavailableException::afterRetries('auth challenge', 3, 'connection timed out'));

        $tested = $this->request($owner, $organization, 'POST', '/api/ksef-connection/test')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $tested['status']);
        self::assertSame('unavailable', $tested['lastCheckError']);
        self::assertStringContainsString('connection timed out', (string) $tested['lastCheckErrorMessage']);
    }

    /** @return iterable<string, array{MembershipRole, string, string, int}> */
    public static function forbidden(): iterable
    {
        yield 'manager saves the token' => [MembershipRole::Manager, 'POST', '/api/ksef-connection/token', 403];
        yield 'manager removes the token' => [MembershipRole::Manager, 'DELETE', '/api/ksef-connection/token', 403];
        yield 'employee reads' => [MembershipRole::Employee, 'GET', '/api/ksef-connection', 403];
        yield 'employee tests' => [MembershipRole::Employee, 'POST', '/api/ksef-connection/test', 403];
        yield 'employee saves the token' => [MembershipRole::Employee, 'POST', '/api/ksef-connection/token', 403];
        yield 'employee removes the token' => [MembershipRole::Employee, 'DELETE', '/api/ksef-connection/token', 403];
    }

    #[DataProvider('forbidden')]
    public function test_it_forbids_what_the_role_may_not_do(MembershipRole $role, string $method, string $url, int $status): void
    {
        [$actor, $organization] = $this->memberOfPolishOrganization($role);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $this->saveToken(OrganizationMother::ownerOf($organization), $organization);

        $this->request($actor, $organization, $method, $url, 'POST' === $method && str_ends_with($url, '/token') ? ['token' => 'another-not-a-real-token'] : null);

        self::assertResponseStatusCodeSame($status);
        $connection = $this->request(OrganizationMother::ownerOf($organization), $organization, 'GET', '/api/ksef-connection')->toArray();
        self::assertTrue($connection['tokenSet']);
        self::assertSame('2026-11-13T10:00:00+00:00', $connection['tokenSetAt']);
    }

    public function test_it_keeps_each_organizations_connection_to_itself(): void
    {
        [$owner, $organization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        [$otherOwner, $otherOrganization] = $this->memberOfPolishOrganization(MembershipRole::Owner);
        self::ksef()->acceptToken(self::NIP, self::TOKEN);
        $saved = $this->saveToken($owner, $organization)->toArray();

        $this->request($otherOwner, $otherOrganization, 'GET', '/api/ksef-connection');
        self::assertResponseStatusCodeSame(404);
        $this->request($otherOwner, $otherOrganization, 'POST', '/api/ksef-connection/test');
        self::assertResponseStatusCodeSame(404);
        $this->request($otherOwner, $otherOrganization, 'DELETE', '/api/ksef-connection/token');
        self::assertResponseStatusCodeSame(404);
        $this->request($otherOwner, $organization, 'GET', '/api/ksef-connection');
        self::assertResponseStatusCodeSame(403);

        $other = $this->saveToken($otherOwner, $otherOrganization, 'another-not-a-real-token')->toArray();

        self::assertNotSame($saved['id'], $other['id']);
        self::assertSame($saved['id'], $this->request($owner, $organization, 'GET', '/api/ksef-connection')->toArray()['id']);
        self::assertSame('active', $this->request($owner, $organization, 'GET', '/api/ksef-connection')->toArray()['status']);
        self::assertSame(2, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM organization_secret'));
    }

    /** @return array{User, Organization} */
    private function memberOfPolishOrganization(MembershipRole $role): array
    {
        [$user, $organization] = self::memberWithRole($role);
        $this->changeSettings($organization, self::NIP, 'PL');
        self::mockTime(self::NOW);

        return [$user, $organization];
    }

    private function changeSettings(Organization $organization, ?string $taxId, string $country): void
    {
        $organization->changeSettings(OrganizationMother::ownerOf($organization)->getId(), $organization->getName(), $taxId, $country, 'PLN', 'Europe/Warsaw');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** The in-memory KSeF of the test container (config/packages/http_client.yaml, when@test). */
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

    private function saveToken(User $owner, Organization $organization, string $token = self::TOKEN): ResponseInterface
    {
        return $this->request($owner, $organization, 'POST', '/api/ksef-connection/token', ['token' => $token]);
    }

    /** @param array<string, mixed>|null $body */
    private function request(User $user, Organization $organization, string $method, string $url, ?array $body = null): ResponseInterface
    {
        $client = self::clientFor($user, $organization);
        $client->disableReboot();
        $response = $client->request($method, $url, null === $body ? [] : ['json' => $body]);
        $this->bodies[] = $response->getContent(false);

        return $response;
    }
}
