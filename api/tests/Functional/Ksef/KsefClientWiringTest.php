<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ksef;

use App\Ksef\Domain\KsefClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The container wires the port to the HTTP adapter, and in tests nothing reaches KSeF (adr/0023 §8). */
final class KsefClientWiringTest extends KernelTestCase
{
    public function test_it_wires_the_port_to_the_http_adapter_that_never_reaches_the_network_in_tests(): void
    {
        // The URL in the message shows the HTTP adapter on the scoped client with KSEF_API_URL from .env.test.
        $client = self::getContainer()->get(KsefClientInterface::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Tests must not reach the network (GET https://ksef.invalid/v2/security/public-key-certificates)');

        $client->authenticateWithKsefToken('7251964308', 'fixture-ksef-token');
    }
}
