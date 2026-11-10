<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

interface EventPublisherInterface
{
    public function publish(EventInterface $event): void;
}
