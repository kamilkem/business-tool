<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Domain\Event\EventInterface;
use App\Shared\Domain\Event\EventPublisherInterface;

/**
 * Fake implementing the port: keeps published events for assertions, and passes each one to a
 * callback (e.g. real event handlers over in-memory repositories), as the synchronous event bus does.
 */
final class DispatchingEventPublisher implements EventPublisherInterface
{
    /** @var list<EventInterface> */
    public array $events = [];

    /** @param \Closure(EventInterface): void $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function publish(EventInterface $event): void
    {
        $this->events[] = $event;
        ($this->handler)($event);
    }

    /**
     * @template T of EventInterface
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (EventInterface $event): bool => $event instanceof $class));
    }
}
