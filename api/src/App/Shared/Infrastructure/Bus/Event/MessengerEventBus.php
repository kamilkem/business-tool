<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Event;

use App\Shared\Domain\Event\EventInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\Shared\Infrastructure\Bus\MessageBusExceptionTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEventBus implements EventPublisherInterface
{
    use MessageBusExceptionTrait;

    public function __construct(
        #[Autowire(service: 'messenger.bus.event')]
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(EventInterface $event): void
    {
        try {
            $this->messageBus->dispatch($event);
        } catch (HandlerFailedException $exception) {
            $this->throwException($exception, $this->logger);
        }
    }
}
