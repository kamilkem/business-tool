<?php

declare(strict_types=1);

namespace Ui\Http\User\Security;

use App\Shared\Application\Command\CommandBusInterface;
use App\User\Application\Command\RevokeSession\RevokeSessionCommand;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * `POST /api/auth/logout` (the firewall's logout path; ADR 0020): revokes the session of the refresh
 * token in the cookie, clears the cookie and answers 204, with or without a valid cookie or JWT.
 */
#[AsEventListener(event: LogoutEvent::class, priority: 128)]
final readonly class RevokeSessionOnLogout
{
    public const string PATH = '/api/auth/logout';

    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $presented = RefreshTokenCookie::fromRequest($event->getRequest());
        if (null !== $presented) {
            $this->commandBus->handle(new RevokeSessionCommand($presented));
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        RefreshTokenCookie::clear($response);
        $event->setResponse($response);
    }
}
