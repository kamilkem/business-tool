<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Job;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJob;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\OrganizationJobInterface;
use App\Shared\Application\Job\ScheduledJob;
use App\Shared\Infrastructure\Bus\Job\MessengerJobBus;
use App\Shared\Infrastructure\Bus\Job\QueueScheduledJobHandler;
use App\Shared\Infrastructure\Bus\Job\SwitchTenantMiddleware;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\Entity\TenantNote;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The jobs runtime of adr/0025 in the container: jobs go to the `jobs` transport (in memory in tests)
 * with their deduplication key, a schedule tick queues its job instead of running it, the console
 * command queues the same jobs, and the tenant middleware runs an organization's job inside it.
 */
#[CoversClass(MessengerJobBus::class)]
#[CoversClass(SwitchTenantMiddleware::class)]
#[CoversClass(QueueScheduledJobHandler::class)]
final class JobBusTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function test_it_queues_jobs_on_the_jobs_transport_once_per_deduplication_key(): void
    {
        $jobBus = self::getContainer()->get(JobBusInterface::class);
        $organizationId = Uuid::v7()->toRfc4122();

        $jobBus->dispatch(new ImportKsefInvoicesJob($organizationId), ImportKsefInvoicesJob::deduplicationKey($organizationId));
        $jobBus->dispatch(new ImportKsefInvoicesJob($organizationId), ImportKsefInvoicesJob::deduplicationKey($organizationId));
        $jobBus->dispatch(new StartKsefImportsJob());

        $sent = $this->sent();
        self::assertCount(2, $sent);
        self::assertEquals(new ImportKsefInvoicesJob($organizationId), $sent[0]->getMessage());
        self::assertSame('ksef-import:'.$organizationId, (string) $sent[0]->last(DeduplicateStamp::class)?->getKey());
        self::assertEquals(new StartKsefImportsJob(), $sent[1]->getMessage());
        self::assertNull($sent[1]->last(DeduplicateStamp::class));
        self::assertSame([], $this->transport('async')->getSent(), 'Jobs never share the e-mail queue.');
    }

    public function test_it_queues_a_scheduled_job_when_the_tick_reaches_the_default_bus(): void
    {
        // The scheduler's consumer dispatches what it receives on the default bus.
        self::getContainer()->get('messenger.default_bus')->dispatch(new Envelope(new ScheduledJob(new StartKsefImportsJob()), [new ReceivedStamp('scheduler_default')]));

        self::assertEquals([new StartKsefImportsJob()], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->sent()));
    }

    public function test_the_console_command_queues_the_fan_out_or_one_organizations_import(): void
    {
        $application = new Application(self::bootKernel());
        $organizationId = Uuid::v7()->toRfc4122();

        $all = new CommandTester($application->find('app:ksef:import'));
        self::assertSame(0, $all->execute([]));
        $one = new CommandTester($application->find('app:ksef:import'));
        self::assertSame(0, $one->execute(['--organization' => $organizationId]));
        $invalid = new CommandTester($application->find('app:ksef:import'));
        self::assertSame(2, $invalid->execute(['--organization' => 'not-an-id']));

        $sent = $this->sent();
        self::assertEquals([new StartKsefImportsJob(), new ImportKsefInvoicesJob($organizationId)], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $sent));
        self::assertSame('ksef-import:'.$organizationId, (string) $sent[1]->last(DeduplicateStamp::class)?->getKey());
    }

    public function test_it_runs_an_organizations_job_inside_it_and_resets_the_tenant_afterwards(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->createNote($a, 'from A');
        $this->createNote($b, 'from B');
        $seen = [];

        $this->middleware()->handle($this->received($a), $this->stack(function () use (&$seen): void {
            $seen = $this->allTexts();
        }));

        self::assertSame(['from A'], $seen);
        self::assertFalse($this->tenantContext()->hasOrganization());
        self::assertSame([], $this->allTexts());
    }

    public function test_it_resets_the_tenant_when_the_job_fails(): void
    {
        $organization = OrganizationFactory::createOne();

        try {
            $this->middleware()->handle($this->received($organization), $this->stack(static function (): void {
                throw new \RuntimeException('The job failed.');
            }));
            self::fail('Exception expected.');
        } catch (\RuntimeException $e) {
            self::assertSame('The job failed.', $e->getMessage());
        }

        self::assertFalse($this->tenantContext()->hasOrganization());
    }

    public function test_it_makes_a_job_of_an_organization_that_no_longer_exists_unrecoverable(): void
    {
        $job = new class implements OrganizationJobInterface {
            public function organizationId(): string
            {
                return '0196f8a2-0000-7000-8000-00000000dead';
            }
        };

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->middleware()->handle(new Envelope($job, [new ReceivedStamp('jobs')]), $this->stack(static fn () => self::fail('The job must not run.')));
    }

    public function test_it_leaves_the_tenant_alone_while_dispatching(): void
    {
        $organization = OrganizationFactory::createOne();
        $ran = false;

        $this->middleware()->handle(new Envelope(new ImportKsefInvoicesJob($organization->getId()->toRfc4122())), $this->stack(function () use (&$ran): void {
            $ran = true;
            self::assertFalse($this->tenantContext()->hasOrganization());
        }));

        self::assertTrue($ran);
    }

    private function received(Organization $organization): Envelope
    {
        return new Envelope(new ImportKsefInvoicesJob($organization->getId()->toRfc4122()), [new ReceivedStamp('jobs')]);
    }

    private function middleware(): SwitchTenantMiddleware
    {
        return self::getContainer()->get(SwitchTenantMiddleware::class);
    }

    /** A stack whose next middleware runs `$handler` in place of the job's handler. */
    private function stack(\Closure $handler): StackInterface
    {
        return new StackMiddleware(new class($handler) implements MiddlewareInterface {
            public function __construct(private readonly \Closure $handler)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->handler)();

                return $envelope;
            }
        });
    }

    private function tenantContext(): TenantContextInterface
    {
        return self::getContainer()->get(TenantContextInterface::class);
    }

    private function createNote(Organization $organization, string $text): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext()->switchTo($entityManager->getReference(Organization::class, $organization->getId()) ?? throw new \LogicException());
        $entityManager->persist(new TenantNote($text));
        $entityManager->flush();
        $entityManager->clear();
        $this->tenantContext()->reset();
    }

    /** @return list<string> */
    private function allTexts(): array
    {
        $notes = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TenantNote::class)->findAll();

        return array_map(static fn (TenantNote $note): string => $note->text, $notes);
    }

    /** @return list<Envelope> */
    private function sent(): array
    {
        return array_values([...$this->transport('jobs')->getSent()]);
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
