<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

/**
 * The test env's `mock_response_factory` (config/packages/http_client.yaml): a test that sends an
 * HTTP request through the container's clients fails instead of reaching the network (adr/0023 §8).
 */
final class NoNetworkResponseFactory
{
    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): never
    {
        throw new \LogicException(\sprintf('Tests must not reach the network (%s %s). Use a MockHttpClient with recorded responses or an in-memory port.', $method, $url));
    }
}
