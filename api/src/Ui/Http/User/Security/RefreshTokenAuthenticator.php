<?php

declare(strict_types=1);

namespace Ui\Http\User\Security;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\RotateRefreshToken\RotateRefreshTokenCommand;
use App\User\Application\Query\GetRefreshToken\GetRefreshTokenQuery;
use App\User\Application\Query\GetRefreshToken\RefreshTokenView;
use App\User\Domain\Exception\InvalidRefreshTokenException;
use App\User\Domain\Model\RefreshTokenSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/auth/refresh` (ADR 0020): rotates the refresh token from the cookie and signs the user
 * in for this request, so Lexik's success handler answers as for a login (`{ token }`), and
 * IssueRefreshTokenOnAuthenticationSuccess sets the successor's cookie. Any failure is a 401 that
 * clears the cookie.
 */
final class RefreshTokenAuthenticator extends AbstractAuthenticator
{
    public const string PATH = '/api/auth/refresh';

    /** The rotated successor, for the success listener: array{id: string, secret: string}. */
    public const string SUCCESSOR_ATTRIBUTE = '_refresh_token_successor';

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $presented = RefreshTokenCookie::fromRequest($request) ?? throw self::failure();

        $successorId = Uuid::v7()->toRfc4122();
        $successorSecret = RefreshTokenSecret::generate()->value;
        try {
            $this->commandBus->handle(new RotateRefreshTokenCommand($presented, $successorId, $successorSecret));
        } catch (InvalidRefreshTokenException) {
            throw self::failure();
        }

        $successor = $this->queryBus->ask(new GetRefreshTokenQuery($successorId));
        if (!$successor instanceof RefreshTokenView) {
            throw self::failure();
        }

        $request->attributes->set(self::SUCCESSOR_ATTRIBUTE, ['id' => $successorId, 'secret' => $successorSecret]);

        return new SelfValidatingPassport(new UserBadge($successor->userEmail));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $response = new JsonResponse(
            ['code' => Response::HTTP_UNAUTHORIZED, 'message' => InvalidRefreshTokenException::create()->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        );
        RefreshTokenCookie::clear($response);

        return $response;
    }

    private static function failure(): CustomUserMessageAuthenticationException
    {
        return new CustomUserMessageAuthenticationException(InvalidRefreshTokenException::create()->getMessage());
    }
}
