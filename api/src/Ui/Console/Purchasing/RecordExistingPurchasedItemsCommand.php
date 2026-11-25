<?php

declare(strict_types=1);

namespace Ui\Console\Purchasing;

use App\Expenses\Application\Query\ListOrganizationsWithPurchaseInvoiceItems\ListOrganizationsWithPurchaseInvoiceItemsQuery;
use App\Purchasing\Application\Job\RecordExistingPurchasedItems\RecordExistingPurchasedItemsJob;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Copies the items of invoices stored before Purchasing existed into purchased items (#240, ADR
 * 0041 §2): queues RecordExistingPurchasedItemsJob for one organization or for each one that has
 * invoice items, and returns; the `jobs-worker` runs them. Run once per environment after the deploy
 * that brought it and after `app:ksef:read-invoice-items` (deploy/README.md); running it again only
 * passes the invoices once more and adds nothing.
 */
#[AsCommand(name: 'app:purchasing:record-existing-items', description: 'Queues copying the items of already stored invoices into purchased items, for every organization with invoice items or for one')]
final readonly class RecordExistingPurchasedItemsCommand
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
            ? $this->queryBus->ask(new ListOrganizationsWithPurchaseInvoiceItemsQuery())
            : [Uuid::fromString($organization)->toRfc4122()];
        foreach ($organizationIds as $organizationId) {
            $this->jobBus->dispatch(new RecordExistingPurchasedItemsJob($organizationId), RecordExistingPurchasedItemsJob::deduplicationKey($organizationId));
        }
        $io->success(\sprintf('Queued recording the purchased items of %d organization(s) (unless already queued or running).', \count($organizationIds)));

        return Command::SUCCESS;
    }
}
