<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Purchasing\Application\Command\LearnProductAliases\LearnProductAliasesCommand;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;

/** What a person confirmed is remembered as aliases (ADR 0042 §2), in the request that confirmed it. */
final readonly class LearnProductAliasesOnPurchasedItemsConfirmed implements EventHandlerInterface
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(PurchasedItemsConfirmed $event): void
    {
        $this->commandBus->handle(new LearnProductAliasesCommand($event->organizationId, $event->itemIds));
    }
}
