<?php

declare(strict_types=1);

namespace Ui\Http\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\User\Application\Command\RequestPasswordReset\RequestPasswordResetCommand;
use App\User\Domain\Model\User;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\RateLimit;
use Ui\Http\User\RequestPasswordResetInput;

/**
 * Requests a password reset e-mail (ADR 0013, #13), after the rate limits per client IP and per
 * e-mail (config/packages/rate_limiter.yaml). Both count every valid request, known e-mail or not,
 * so neither the answer nor the limit tells whether an account exists.
 *
 * @implements ProcessorInterface<RequestPasswordResetInput, null>
 */
final readonly class RequestPasswordResetProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private RequestStack $requestStack,
        #[Target('password_reset_per_ip')]
        private RateLimiterFactoryInterface $perIpLimiter,
        #[Target('password_reset_per_email')]
        private RateLimiterFactoryInterface $perEmailLimiter,
    ) {
    }

    /** @param RequestPasswordResetInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $clientIp = $this->requestStack->getMainRequest()?->getClientIp() ?? 'unknown';
        RateLimit::consumeOrFail($this->perIpLimiter->create($clientIp));
        RateLimit::consumeOrFail($this->perEmailLimiter->create(User::normalizeEmail($data->email)));

        $this->commandBus->handle(new RequestPasswordResetCommand(Uuid::v7()->toRfc4122(), $data->email, $data->app->value));

        return null;
    }
}
