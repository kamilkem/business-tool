<?php

declare(strict_types=1);

namespace Ui\Console\Purchasing;

use App\Expenses\Application\Query\ListOrganizationsWithPurchaseInvoiceItems\ListOrganizationsWithPurchaseInvoiceItemsQuery;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJob;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Queues BackfillProductAliasesJob (ADR 0045 §3) for one organization or for each one that has invoice
 * items, and returns; the `jobs-worker` runs them. It teaches the aliases that items confirmed before
 * aliases were learned would have taught, only for keys that have none, and changes no item or link. Run
 * it once per environment, before `app:purchasing:match-items`, which then uses the aliases; running it
 * again adds nothing.
 */
#[AsCommand(name: 'app:purchasing:backfill-aliases', description: 'Queues learning aliases from items confirmed before aliases existed, for every organization with invoice items or for one')]
final readonly class BackfillProductAliasesCommand
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
            $this->jobBus->dispatch(new BackfillProductAliasesJob($organizationId), BackfillProductAliasesJob::deduplicationKey($organizationId));
        }
        $io->success(\sprintf('Queued learning the aliases of %d organization(s) (unless already queued or running).', \count($organizationIds)));

        return Command::SUCCESS;
    }
}
