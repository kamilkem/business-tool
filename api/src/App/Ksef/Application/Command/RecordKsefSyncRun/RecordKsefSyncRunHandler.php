<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RecordKsefSyncRun;

use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

final readonly class RecordKsefSyncRunHandler implements CommandHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @throws KsefConnectionNotFoundException when the current organization has no KSeF connection */
    public function __invoke(RecordKsefSyncRunCommand $command): void
    {
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();
        $connection->recordSyncRun(
            new \DateTimeImmutable($command->ranAt),
            $command->imported,
            $command->skipped,
            null === $command->error ? null : KsefErrorCode::from($command->error),
            $command->errorMessage,
            null === $command->retryNotBefore ? null : new \DateTimeImmutable($command->retryNotBefore),
        );

        $this->connections->save($connection);
    }
}
