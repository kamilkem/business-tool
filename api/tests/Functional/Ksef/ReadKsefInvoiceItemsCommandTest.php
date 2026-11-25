<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ksef;

use App\Ksef\Application\Job\ReadKsefInvoiceItems\ReadKsefInvoiceItemsJob;
use App\Ksef\Application\Query\ListKsefConnectedOrganizations\ListKsefConnectedOrganizationsHandler;
use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ui\Console\Ksef\ReadKsefInvoiceItemsCommand;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** `app:ksef:read-invoice-items` (#230) in the container: it queues the job on the `jobs` transport (in memory in tests) and returns. */
#[CoversClass(ReadKsefInvoiceItemsCommand::class)]
#[CoversClass(ListKsefConnectedOrganizationsHandler::class)]
final class ReadKsefInvoiceItemsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ?Application $application = null;

    public function test_it_queues_the_job_for_one_organization_with_its_deduplication_key(): void
    {
        $organizationId = Uuid::v7()->toRfc4122();

        self::assertSame(0, $this->execute(['--organization' => strtoupper($organizationId)]));

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertEquals(new ReadKsefInvoiceItemsJob($organizationId), $sent[0]->getMessage());
        self::assertSame('ksef-read-invoice-items:'.$organizationId, (string) $sent[0]->last(DeduplicateStamp::class)?->getKey());
    }

    public function test_it_queues_the_job_for_every_organization_with_a_ksef_connection(): void
    {
        $withToken = $this->connect(OrganizationFactory::createOne());
        $tokenRemoved = $this->connect(OrganizationFactory::createOne(), removeToken: true);
        OrganizationFactory::createOne();
        $expected = [$withToken->getId()->toRfc4122(), $tokenRemoved->getId()->toRfc4122()];
        sort($expected);

        self::assertSame(0, $this->execute([]));

        $sent = $this->sent();
        self::assertEquals(array_map(static fn (string $id): ReadKsefInvoiceItemsJob => new ReadKsefInvoiceItemsJob($id), $expected), array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $sent));
        self::assertSame(
            array_map(static fn (string $id): string => 'ksef-read-invoice-items:'.$id, $expected),
            array_map(static fn (Envelope $envelope): string => (string) $envelope->last(DeduplicateStamp::class)?->getKey(), $sent),
        );
    }

    public function test_it_queues_nothing_twice_while_the_job_waits(): void
    {
        $organizationId = Uuid::v7()->toRfc4122();

        $this->execute(['--organization' => $organizationId]);
        $this->execute(['--organization' => $organizationId]);

        self::assertCount(1, $this->sent());
    }

    public function test_it_refuses_an_invalid_organization_id(): void
    {
        self::assertSame(2, $this->execute(['--organization' => 'not-an-id']));
        self::assertSame([], $this->sent());
    }

    /** @param array<string, string> $input */
    private function execute(array $input): int
    {
        $this->application ??= new Application(self::bootKernel());

        return new CommandTester($this->application->find('app:ksef:read-invoice-items'))->execute($input);
    }

    private function connect(Organization $organization, bool $removeToken = false): Organization
    {
        $tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($organization);
        $connection = KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', '1111111111', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-10 12:00:00'));
        if ($removeToken) {
            $connection->tokenRemoved(Uuid::v7(), MembershipRole::Owner);
        }
        self::getContainer()->get(KsefConnectionRepositoryInterface::class)->add($connection);
        $tenantContext->reset();

        return $organization;
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        \assert($transport instanceof InMemoryTransport);

        return array_values($transport->getSent());
    }
}
