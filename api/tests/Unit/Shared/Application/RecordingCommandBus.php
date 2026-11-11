<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;

/** Fake bus: keeps dispatched commands for assertions and handles none. */
final class RecordingCommandBus implements CommandBusInterface
{
    /** @var list<CommandInterface> */
    public array $commands = [];

    public function handle(CommandInterface $command): void
    {
        $this->commands[] = $command;
    }
}
