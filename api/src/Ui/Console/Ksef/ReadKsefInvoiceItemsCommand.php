<?php

declare(strict_types=1);

namespace Ui\Console\Ksef;

use App\Ksef\Application\Job\ReadKsefInvoiceItems\ReadKsefInvoiceItemsJob;
use App\Ksef\Application\Query\ListKsefConnectedOrganizations\ListKsefConnectedOrganizationsQuery;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the items of KSeF invoices imported before items were kept, from their stored XML (#230,
 * ADR 0040): queues ReadKsefInvoiceItemsJob for one organization or for each one with a KSeF
 * connection, and returns; the `jobs-worker` runs them. Run once per environment after the deploy
 * that brought it (deploy/README.md); running it again only passes the invoices still without items.
 */
#[AsCommand(name: 'app:ksef:read-invoice-items', description: 'Queues reading the items of KSeF invoices imported without them, for every organization with a KSeF connection or for one')]
final readonly class ReadKsefInvoiceItemsCommand
{
    public function __construct(
        private JobBusInterface $jobBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Queue it for this organization only (its id)')]
        ?string $organization = null,
    ): int {
        if (null !== $organization && !Uuid::isValid($organization)) {
            $io->error(\sprintf('"%s" is not an organization id.', $organization));

            return Command::INVALID;
        }

        /** @var list<string> $organizationIds */
        $organizationIds = null === $organization
            ? $this->queryBus->ask(new ListKsefConnectedOrganizationsQuery())
            : [Uuid::fromString($organization)->toRfc4122()];
        foreach ($organizationIds as $organizationId) {
            $this->jobBus->dispatch(new ReadKsefInvoiceItemsJob($organizationId), ReadKsefInvoiceItemsJob::deduplicationKey($organizationId));
        }
        $io->success(\sprintf('Queued reading the KSeF invoice items of %d organization(s) (unless already queued or running).', \count($organizationIds)));

        return Command::SUCCESS;
    }
}
