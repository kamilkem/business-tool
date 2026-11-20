<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\AdvanceKsefSyncCursor;

use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

final readonly class AdvanceKsefSyncCursorHandler implements CommandHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @throws KsefConnectionNotFoundException when the current organization has no KSeF connection */
    public function __invoke(AdvanceKsefSyncCursorCommand $command): void
    {
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();
        $connection->advanceSyncCursor(new \DateTimeImmutable($command->cursor));

        $this->connections->save($connection);
    }
}
