<?php

declare(strict_types=1);

namespace Ui\Console\Purchasing;

use App\Expenses\Application\Query\ListOrganizationsWithPurchaseInvoiceItems\ListOrganizationsWithPurchaseInvoiceItemsQuery;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Queues MatchPurchasedItemsJob (ADR 0042 §7) for one organization or for each one that has invoice
 * items, and returns; the `jobs-worker` runs them. By default it matches the items the model never
 * saw: run it once per environment after `app:purchasing:record-existing-items`. `--again` also puts
 * the unassigned items the model found nothing for to it, for after the catalog has grown.
 */
#[AsCommand(name: 'app:purchasing:match-items', description: 'Queues matching purchased items to products, for every organization with invoice items or for one')]
final readonly class MatchPurchasedItemsCommand
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
        #[Option(description: 'Go through every unassigned item again, also those the model found nothing for')]
        bool $again = false,
    ): int {
        if (null !== $organization && !Uuid::isValid($organization)) {
            $io->error(\sprintf('"%s" is not an organization id.', $organization));

            return Command::INVALID;
        }

        $scope = $again ? MatchScope::Unassigned : MatchScope::NotYetMatched;
        /** @var list<string> $organizationIds */
        $organizationIds = null === $organization
            ? $this->queryBus->ask(new ListOrganizationsWithPurchaseInvoiceItemsQuery())
            : [Uuid::fromString($organization)->toRfc4122()];
        foreach ($organizationIds as $organizationId) {
            $this->jobBus->dispatch(
                new MatchPurchasedItemsJob($organizationId, $scope->value, true),
                MatchPurchasedItemsJob::deduplicationKey($organizationId, $scope->value, true),
            );
        }
        $io->success(\sprintf('Queued matching the purchased items of %d organization(s) (unless already queued or running).', \count($organizationIds)));

        return Command::SUCCESS;
    }
}
