<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Domain\Event\EventInterface;
use App\Shared\Domain\Event\EventPublisherInterface;

/** Fake implementing the port: keeps published events for assertions. */
final class RecordingEventPublisher implements EventPublisherInterface
{
    /** @var list<EventInterface> */
    public array $events = [];

    public function publish(EventInterface $event): void
    {
        $this->events[] = $event;
    }
}
