<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ksef;

use App\Ksef\Infrastructure\Http\HttpKsefClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The HTTP adapter is wired on the scoped client and, in tests, never reaches KSeF (adr/0023 §8). The
 * port itself is the in-memory KSeF in tests (config/packages/http_client.yaml).
 */
final class KsefClientWiringTest extends KernelTestCase
{
    public function test_it_wires_the_http_adapter_that_never_reaches_the_network_in_tests(): void
    {
        // The URL in the message shows the HTTP adapter on the scoped client with KSEF_API_URL from .env.test.
        $client = self::getContainer()->get(HttpKsefClient::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Tests must not reach the network (GET https://ksef.invalid/v2/security/public-key-certificates)');

        $client->authenticateWithKsefToken('7251964308', 'fixture-ksef-token');
    }
}
