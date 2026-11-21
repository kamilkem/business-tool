<?php

declare(strict_types=1);

namespace Ui\Http\Shared\Problem;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ApiResource\Error;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns API Platform's problem for a failed request into a `CodedError`, which carries the domain
 * exception's code and parameters (ADR 0028 §5). Validation errors (422 with `violations`) are not an
 * `Error` and pass through: their messages are translated by the API.
 *
 * @implements ProviderInterface<object>
 */
#[AsDecorator('api_platform.state.error_provider')]
final readonly class CodedErrorProvider implements ProviderInterface
{
    /** @param ProviderInterface<object> $decorated */
    public function __construct(
        #[AutowireDecorated]
        private ProviderInterface $decorated,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $error = $this->decorated->provide($operation, $uriVariables, $context);
        if (!$error instanceof Error || $error instanceof CodedError) {
            return $error;
        }

        $request = $context['request'] ?? null;
        $exception = $request instanceof Request ? $request->attributes->get('exception') : null;

        return CodedError::of($error, $exception instanceof \Throwable ? $exception : null);
    }
}
