<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJob;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ui\Console\Purchasing\BackfillProductAliasesCommand;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** `app:purchasing:backfill-aliases` (ADR 0045 §3) in the container: it queues the job on the `jobs` transport (in memory in tests) and returns. */
#[CoversClass(BackfillProductAliasesCommand::class)]
final class BackfillProductAliasesCommandTest extends KernelTestCase
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
        self::assertEquals(new BackfillProductAliasesJob($organizationId), $sent[0]->getMessage());
        self::assertSame('purchasing-backfill-aliases:'.$organizationId, (string) $sent[0]->last(DeduplicateStamp::class)?->getKey());
    }

    public function test_it_queues_the_job_for_every_organization_that_has_invoice_items(): void
    {
        $a = $this->withItems(OrganizationFactory::createOne());
        $b = $this->withItems(OrganizationFactory::createOne());
        OrganizationFactory::createOne();
        $expected = [$a->getId()->toRfc4122(), $b->getId()->toRfc4122()];
        sort($expected);

        self::assertSame(0, $this->execute([]));

        self::assertEquals(
            array_map(static fn (string $id): BackfillProductAliasesJob => new BackfillProductAliasesJob($id), $expected),
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->sent()),
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

        return new CommandTester($this->application->find('app:purchasing:backfill-aliases'))->execute($input);
    }

    private function withItems(Organization $organization): Organization
    {
        $tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($organization);
        self::getContainer()->get(PurchaseInvoiceRepositoryInterface::class)->add(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));
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
