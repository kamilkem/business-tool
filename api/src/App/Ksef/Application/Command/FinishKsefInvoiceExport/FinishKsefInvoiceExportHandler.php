<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\FinishKsefInvoiceExport;

use App\Ksef\Domain\Exception\KsefConnectionNotFoundException;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

final readonly class FinishKsefInvoiceExportHandler implements CommandHandlerInterface
{
    public function __construct(private KsefConnectionRepositoryInterface $connections)
    {
    }

    /** @throws KsefConnectionNotFoundException when the current organization has no KSeF connection */
    public function __invoke(FinishKsefInvoiceExportCommand $command): void
    {
        $connection = $this->connections->findCurrent() ?? throw KsefConnectionNotFoundException::create();
        $connection->exportFinished($command->referenceNumber);
        $this->connections->save($connection);
    }
}
