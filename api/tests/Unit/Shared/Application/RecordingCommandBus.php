<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;

/**
 * Fake bus: keeps dispatched commands for assertions. Handles none, unless given a callback that
 * passes them on (e.g. to real handlers over in-memory repositories, for job handler tests).
 */
final class RecordingCommandBus implements CommandBusInterface
{
    /** @var list<CommandInterface> */
    public array $commands = [];

    /** @param (\Closure(CommandInterface): void)|null $handler */
    public function __construct(private readonly ?\Closure $handler = null)
    {
    }

    public function handle(CommandInterface $command): void
    {
        $this->commands[] = $command;
        if (null !== $this->handler) {
            ($this->handler)($command);
        }
    }

    /**
     * @template T of CommandInterface
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function commandsOf(string $class): array
    {
        return array_values(array_filter($this->commands, static fn (CommandInterface $command): bool => $command instanceof $class));
    }
}
