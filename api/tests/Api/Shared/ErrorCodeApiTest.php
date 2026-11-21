<?php

declare(strict_types=1);

namespace App\Tests\Api\Shared;

use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Problem responses carry the domain exception's `code` and `parameters` next to the English
 * `detail` (ADR 0028 §5), whatever the language asked for; any other error has a null code.
 */
final class ErrorCodeApiTest extends ApiTestCase
{
    public function test_it_gives_the_code_and_parameters_of_a_404(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        $response = static::clientFor($owner, $organization)->request('GET', '/api/registers/'.$unknown);

        self::assertProblem($response, 404, 'income.register_not_found', ['registerId' => $unknown]);
        self::assertSame(\sprintf('Register "%s" not found.', $unknown), $response->toArray(false)['detail']);
    }

    public function test_it_gives_the_code_and_parameters_of_a_409(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addRegister($organization, 'Bar');

        $response = static::clientFor($owner, $organization)->request('POST', '/api/registers', [
            'json' => ['name' => 'Bar'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertProblem($response, 409, 'income.register_name_taken', ['name' => 'Bar']);
        self::assertSame('The organization already has a register named "Bar".', $response->toArray(false)['detail']);
    }

    public function test_it_gives_the_code_and_parameters_of_a_domain_422(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime('2026-11-20 10:00:00');

        $response = static::clientFor($owner, $organization)->request('POST', '/api/payroll-runs', ['json' => ['month' => '2026-12']]);

        self::assertProblem($response, 422, 'payroll.month_in_the_future', ['month' => '2026-12']);
    }

    public function test_it_gives_the_code_of_a_domain_403_with_its_variant(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);

        $response = static::clientFor($employee, $organization)->request('POST', '/api/payroll-runs', ['json' => ['month' => '2026-10']]);

        self::assertProblem($response, 403, 'payroll.access_forbidden.to_manage', ['userId' => $employee->getId()->toRfc4122()]);
    }

    public function test_it_gives_the_code_of_a_time_tracking_409(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);

        $response = static::clientFor($employee, $organization)->request('POST', '/api/shifts/clock-out', ['json' => []]);

        self::assertProblem($response, 409, 'time_tracking.not_clocked_in', ['userId' => $employee->getId()->toRfc4122()]);
    }

    public function test_it_gives_no_code_for_an_error_that_is_not_a_domain_exception(): void
    {
        $user = UserFactory::createOne();
        [, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($user, $organization)->request('GET', '/api/registers');

        self::assertResponseStatusCodeSame(403);
        $problem = $response->toArray(false);
        self::assertArrayHasKey('code', $problem);
        self::assertNull($problem['code']);
        self::assertStringContainsString('"parameters":{}', $response->getContent(false));
    }

    public function test_it_hides_another_organizations_data_in_the_parameters_too(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addRegister($elsewhere, 'Kitchen')->getId()->toRfc4122();

        $response = static::clientFor($owner, $organization)->request('GET', '/api/registers/'.$id);

        self::assertProblem($response, 404, 'income.register_not_found', ['registerId' => $id]);
        self::assertStringNotContainsString('Kitchen', $response->getContent(false));
    }

    /** @param array<string, mixed> $parameters */
    private static function assertProblem(ResponseInterface $response, int $status, string $code, array $parameters): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', $response->getHeaders(false)['content-type'][0] ?? '');
        $problem = $response->toArray(false);
        self::assertSame($status, $problem['status']);
        self::assertSame($code, $problem['code']);
        self::assertSame($parameters, $problem['parameters']);
    }

    private static function addRegister(Organization $organization, string $name): Register
    {
        $owner = OrganizationMother::ownerOf($organization);
        $register = Register::create(Uuid::v7(), $name, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            static::getContainer()->get(RegisterRepositoryInterface::class)->add($register);
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }

        return $register;
    }
}
