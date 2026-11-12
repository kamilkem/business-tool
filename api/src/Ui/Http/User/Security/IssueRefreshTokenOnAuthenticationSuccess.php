<?php

declare(strict_types=1);

namespace Ui\Http\User\Security;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\IssueRefreshToken\IssueRefreshTokenCommand;
use App\User\Application\Query\GetRefreshToken\GetRefreshTokenQuery;
use App\User\Application\Query\GetRefreshToken\RefreshTokenView;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Model\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Sets the refresh token cookie next to every JWT that Lexik's success handler returns (ADR 0020):
 * a new session at sign-in (`json_login`), or the successor that RefreshTokenAuthenticator rotated.
 */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
final readonly class IssueRefreshTokenOnAuthenticationSuccess
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        /** @var array{id: string, secret: string}|null $successor */
        $successor = $this->requestStack->getMainRequest()?->attributes->get(RefreshTokenAuthenticator::SUCCESSOR_ATTRIBUTE);
        if (null === $successor) {
            $successor = ['id' => Uuid::v7()->toRfc4122(), 'secret' => RefreshTokenSecret::generate()->value];
            $this->commandBus->handle(new IssueRefreshTokenCommand(
                $successor['id'],
                Uuid::v7()->toRfc4122(),
                $user->getId()->toRfc4122(),
                $successor['secret'],
            ));
        }

        $view = $this->queryBus->ask(new GetRefreshTokenQuery($successor['id']));
        if (!$view instanceof RefreshTokenView) {
            throw new \LogicException('The refresh token just issued was not found.');
        }

        RefreshTokenCookie::set($event->getResponse(), $successor['secret'], $view->expiresAt);
    }
}
