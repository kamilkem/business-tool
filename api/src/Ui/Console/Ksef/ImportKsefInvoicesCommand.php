<?php

declare(strict_types=1);

namespace Ui\Console\Ksef;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJob;
use App\Shared\Application\Job\JobBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Manual KSeF import (ADR 0025 §6): queues the same jobs as the schedule and "Import now", and
 * returns; the `jobs-worker` runs them (`messenger:consume jobs --limit=1` runs one in the foreground).
 */
#[AsCommand(name: 'app:ksef:import', description: 'Queues the KSeF purchase invoice import for every organization that may sync, or for one')]
final readonly class ImportKsefInvoicesCommand
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Queue the import of this organization only (its id)')]
        ?string $organization = null,
    ): int {
        if (null === $organization) {
            $this->jobBus->dispatch(new StartKsefImportsJob());
            $io->success('Queued the KSeF import of every organization that may sync.');

            return Command::SUCCESS;
        }

        if (!Uuid::isValid($organization)) {
            $io->error(\sprintf('"%s" is not an organization id.', $organization));

            return Command::INVALID;
        }

        $organizationId = Uuid::fromString($organization)->toRfc4122();
        $this->jobBus->dispatch(new ImportKsefInvoicesJob($organizationId), ImportKsefInvoicesJob::deduplicationKey($organizationId));
        $io->success(\sprintf('Queued the KSeF import of the organization %s (unless one is already queued or running).', $organizationId));

        return Command::SUCCESS;
    }
}
