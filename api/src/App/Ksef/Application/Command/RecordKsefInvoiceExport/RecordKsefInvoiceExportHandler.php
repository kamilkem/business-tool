<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RecordKsefInvoiceExport;

use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Model\KsefInvoiceExport;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

final readonly class RecordKsefInvoiceExportHandler implements CommandHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @throws KsefConnectionNotFoundException when the current organization has no KSeF connection */
    public function __invoke(RecordKsefInvoiceExportCommand $command): void
    {
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();
        $connection->exportStarted(new KsefInvoiceExport(
            $command->referenceNumber,
            $command->keyId,
            new \DateTimeImmutable($command->from),
            new \DateTimeImmutable($command->to),
            new \DateTimeImmutable($command->startedAt),
        ));
        $this->connections->save($connection);
    }
}
