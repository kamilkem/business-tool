<?php

declare(strict_types=1);

namespace Ui\Http\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\User\Application\Command\SetPassword\SetPasswordCommand;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Ui\Http\User\SetPasswordInput;

/**
 * Sets a password with an e-mailed token (ADR 0013), after the per-IP rate limit
 * (config/packages/rate_limiter.yaml). Every valid request counts, whether the token works or not.
 *
 * @implements ProcessorInterface<SetPasswordInput, null>
 */
final readonly class SetPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private RequestStack $requestStack,
        #[Target('set_password')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** @param SetPasswordInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $clientIp = $this->requestStack->getMainRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->limiter->create($clientIp)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many attempts. Try again later.');
        }

        $this->commandBus->handle(new SetPasswordCommand($data->token, $data->password));

        return null;
    }
}
