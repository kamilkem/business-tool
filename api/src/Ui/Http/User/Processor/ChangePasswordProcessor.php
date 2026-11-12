<?php

declare(strict_types=1);

namespace Ui\Http\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\User\Application\Command\ChangePassword\ChangePasswordCommand;
use App\User\Application\Command\IssueRefreshToken\IssueRefreshTokenCommand;
use App\User\Application\Query\GetRefreshToken\GetRefreshTokenQuery;
use App\User\Application\Query\GetRefreshToken\RefreshTokenView;
use App\User\Domain\Model\RefreshTokenSecret;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\User\ChangePasswordInput;
use Ui\Http\User\Security\RefreshTokenCookie;

/**
 * Changes the signed-in user's password (#12). The command ends every session of the user (ADR 0020);
 * this device then gets a new one, as at sign-in, so the person stays signed in here: the response
 * sets a fresh refresh token cookie, and the JWT the client holds keeps working until it expires.
 *
 * @implements ProcessorInterface<ChangePasswordInput, Response>
 */
final readonly class ChangePasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
    ) {
    }

    /** @param ChangePasswordInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $userId = $this->currentUser->id();
        $this->commandBus->handle(new ChangePasswordCommand($userId, $data->newPassword));

        $refreshTokenId = Uuid::v7()->toRfc4122();
        $secret = RefreshTokenSecret::generate()->value;
        $this->commandBus->handle(new IssueRefreshTokenCommand($refreshTokenId, Uuid::v7()->toRfc4122(), $userId, $secret));
        $view = $this->queryBus->ask(new GetRefreshTokenQuery($refreshTokenId));
        if (!$view instanceof RefreshTokenView) {
            throw new \LogicException('The refresh token just issued was not found.');
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        RefreshTokenCookie::set($response, $secret, $view->expiresAt);

        return $response;
    }
}
