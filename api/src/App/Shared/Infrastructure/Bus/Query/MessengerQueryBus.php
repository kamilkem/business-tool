<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Query;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Infrastructure\Bus\MessageBusExceptionTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class MessengerQueryBus implements QueryBusInterface
{
    use MessageBusExceptionTrait;

    public function __construct(
        #[Autowire(service: 'messenger.bus.query')]
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function ask(QueryInterface $query): mixed
    {
        try {
            $envelope = $this->messageBus->dispatch($query);
        } catch (HandlerFailedException $exception) {
            $this->throwException($exception, $this->logger);
        }

        return $envelope->last(HandledStamp::class)?->getResult();
    }
}
