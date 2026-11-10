<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

trait MessageBusExceptionTrait
{
    /**
     * Rethrows the original exception of the first failed handler, so domain exceptions reach
     * API Platform's exception_to_status mapping unwrapped.
     *
     * Messenger runs every handler of a message and collects all failures, but the caller can only
     * receive one, so every other failure is logged first. The log names the message class and the
     * handler, never the payload.
     */
    private function throwException(HandlerFailedException $exception, LoggerInterface $logger): never
    {
        $current = $exception;
        $this->logSuppressedFailures($current, $logger);

        while (($previous = $current->getPrevious()) instanceof HandlerFailedException) {
            $this->logSuppressedFailures($previous, $logger);
            $current = $previous;
        }

        throw $previous ?? $exception;
    }

    private function logSuppressedFailures(HandlerFailedException $exception, LoggerInterface $logger): void
    {
        foreach (\array_slice($exception->getWrappedExceptions(), 1, null, true) as $handler => $failure) {
            $logger->error('A message handler failed after the first failure, which is the one rethrown.', [
                'message_class' => $exception->getEnvelope()->getMessage()::class,
                'handler' => $handler,
                'exception' => $failure,
            ]);
        }
    }
}
