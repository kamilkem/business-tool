<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Ksef\Infrastructure\Http\HttpKsefClient;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The KSeF adapter against recorded and OpenAPI-derived responses (tests/Fixtures/Ksef/README.md);
 * nothing reaches the network.
 */
final class HttpKsefClientTest extends TestCase
{
    private const string API_URL = 'https://ksef.example/v2';
    private const string NIP = '7251964308';
    private const string KSEF_TOKEN = 'fixture-ksef-token';
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Ksef/';

    private MockClock $clock;
    private ArrayAdapter $cache;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-12 22:42:47 UTC');
        $this->cache = new ArrayAdapter();
        $this->requests = [];
    }

    public function test_it_authenticates_with_a_ksef_token_encrypted_with_rsa_oaep_sha256(): void
    {
        [$privateKey, $certificates] = self::generatedCertificates();
        $client = $this->client([
            self::json($certificates),
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            self::fixture('auth-status-in-progress.json'),
            self::fixture('auth-status-succeeded.json'),
            self::fixture('auth-token-redeem.json'),
        ]);

        $session = $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);

        self::assertSame(
            [
                'GET https://ksef.example/v2/security/public-key-certificates',
                'POST https://ksef.example/v2/auth/challenge',
                'POST https://ksef.example/v2/auth/ksef-token',
                'GET https://ksef.example/v2/auth/20261112-AU-2C9EA54000-C4E1F6E27B-6F',
                'GET https://ksef.example/v2/auth/20261112-AU-2C9EA54000-C4E1F6E27B-6F',
                'POST https://ksef.example/v2/auth/token/redeem',
            ],
            $this->requestLines(),
        );

        $init = $this->jsonBody(2);
        self::assertSame('20261112-CR-4DFAE3F000-99406CBAF1-21', $init['challenge']);
        self::assertSame(['type' => 'Nip', 'value' => self::NIP], $init['contextIdentifier']);
        self::assertSame('generatedKeyIdgeneratedKeyIdgeneratedKeyId=', $init['publicKeyId']);
        self::assertIsString($init['encryptedToken']);
        $decrypted = $privateKey->decrypt((string) base64_decode($init['encryptedToken'], true));
        self::assertSame(self::KSEF_TOKEN.'|1794523367999', $decrypted);

        self::assertSame('Bearer fixture-authentication-token', $this->header(3, 'authorization'));
        self::assertSame('Bearer fixture-authentication-token', $this->header(5, 'authorization'));
        self::assertSame('problem-details', $this->header(1, 'x-error-format'));

        self::assertSame(self::NIP, $session->contextNip);
        self::assertSame('20261112-AU-2C9EA54000-C4E1F6E27B-6F', $session->referenceNumber);
        self::assertSame('fixture-access-token', $session->accessToken());
        self::assertSame('fixture-refresh-token', $session->refreshToken());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:57:49.234567 UTC'), $session->accessTokenValidUntil());
        self::assertEquals(new \DateTimeImmutable('2026-11-19 22:42:49.234567 UTC'), $session->refreshTokenValidUntil);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:42:48 UTC'), $this->clock->now(), 'one poll interval while in progress');
    }

    public function test_it_encrypts_with_the_recorded_test_environment_key(): void
    {
        $client = $this->client([
            self::fixture('public-key-certificates.json'),
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            self::fixture('auth-status-succeeded.json'),
            self::fixture('auth-token-redeem.json'),
        ]);

        $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);

        $init = $this->jsonBody(2);
        self::assertSame('IPbPM4CB49vtoR/x/3fEI+Y+Q6lK/bVVehQ7/NlPJoo=', $init['publicKeyId'], 'the KsefTokenEncryption key, not the SymmetricKeyEncryption one');
        self::assertIsString($init['encryptedToken']);
        self::assertSame(256, \strlen((string) base64_decode($init['encryptedToken'], true)), 'one RSA-2048 block');
    }

    public function test_it_fetches_the_public_key_once_while_it_is_valid(): void
    {
        $client = $this->client([
            self::fixture('public-key-certificates.json'),
            ...self::authenticationAfterKey(),
            ...self::authenticationAfterKey(),
        ]);

        $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);
        $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);

        self::assertCount(1, array_filter($this->requestLines(), static fn (string $line): bool => str_ends_with($line, '/security/public-key-certificates')));
    }

    public function test_it_fails_without_a_valid_token_encryption_key(): void
    {
        $certificates = json_decode((string) file_get_contents(self::FIXTURES.'public-key-certificates.json'), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($certificates);
        $client = $this->client([self::json([$certificates[1]])]);

        $this->expectException(KsefRequestFailedException::class);
        $this->expectExceptionMessage('KsefTokenEncryption');

        $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);
    }

    public function test_it_reports_a_rejected_token_as_rejected_credentials(): void
    {
        $client = $this->client([
            self::fixture('public-key-certificates.json'),
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            self::fixture('auth-status-invalid-token.json'),
        ]);

        try {
            $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);
            self::fail('Expected the credentials to be rejected.');
        } catch (KsefCredentialsRejectedException $e) {
            self::assertSame(450, $e->statusCode);
            self::assertSame(['Token unieważniony'], $e->details);
        }
        self::assertCount(4, $this->requests, 'no redemption after a rejection');
    }

    public function test_it_reports_an_authentication_cancelled_by_ksef_as_unavailable(): void
    {
        $client = $this->client([
            self::fixture('public-key-certificates.json'),
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            self::fixture('auth-status-cancelled.json'),
        ]);

        $this->expectException(KsefUnavailableException::class);
        $this->expectExceptionMessage('status 550');

        $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);
    }

    public function test_it_gives_up_when_the_authentication_stays_in_progress(): void
    {
        $client = $this->client([
            self::fixture('public-key-certificates.json'),
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            ...array_fill(0, HttpKsefClient::MAX_STATUS_POLLS, self::fixture('auth-status-in-progress.json')),
        ]);

        try {
            $client->authenticateWithKsefToken(self::NIP, self::KSEF_TOKEN);
            self::fail('Expected the authentication to time out.');
        } catch (KsefUnavailableException $e) {
            self::assertStringContainsString('still in progress', $e->getMessage());
        }
        self::assertCount(3 + HttpKsefClient::MAX_STATUS_POLLS, $this->requests);
    }

    public function test_it_queries_purchase_invoice_metadata_by_permanent_storage_date(): void
    {
        $client = $this->client([self::fixture('invoices-query-metadata.json')]);
        $query = new KsefPurchaseInvoiceQuery(
            new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('Europe/Warsaw')),
            new \DateTimeImmutable('2026-11-12 22:42:47.5 UTC'),
            2,
        );

        $page = $client->queryPurchaseInvoiceMetadata($this->session(), $query);

        self::assertSame(['POST https://ksef.example/v2/invoices/query/metadata?sortOrder=Asc&pageOffset=2&pageSize=250'], $this->requestLines());
        self::assertSame('Bearer fixture-access-token', $this->header(0, 'authorization'));
        self::assertSame([
            'subjectType' => 'Subject2',
            'dateRange' => [
                'dateType' => 'PermanentStorage',
                'from' => '2026-09-30T22:00:00.000+00:00',
                'to' => '2026-11-12T22:42:47.500+00:00',
                'restrictToPermanentStorageHwmDate' => true,
            ],
        ], $this->jsonBody(0));

        self::assertTrue($page->hasMore);
        self::assertFalse($page->isTruncated);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:30:00.123456 UTC'), $page->permanentStorageHwmDate);
        self::assertCount(2, $page->invoices);
        [$fa3, $fa2] = $page->invoices;
        self::assertSame('3947150208-20261103-0100A0B1C2D3-4F', $fa3->ksefNumber);
        self::assertTrue($fa3->formCode->isFa3());
        self::assertSame('1-0E', $fa3->formCode->schemaVersion);
        self::assertSame('FA', $fa3->formCode->value);
        self::assertSame('mkht+3m5trnfxlTYhq3QFn74LkEO69MFNlsMAkCDSPA=', $fa3->invoiceHash);
        self::assertEquals(new \DateTimeImmutable('2026-11-03 09:15:07.388123 UTC'), $fa3->permanentStorageDate);
        self::assertSame('UTC', $fa3->permanentStorageDate->getTimezone()->getName());
        self::assertSame('2026-11-03', $fa3->issueDate->toString());
        self::assertFalse($fa2->formCode->isFa3());
    }

    public function test_it_reads_a_last_page_without_a_high_water_mark(): void
    {
        $client = $this->client([self::fixture('invoices-query-metadata-empty.json')]);

        $page = $client->queryPurchaseInvoiceMetadata($this->session(), new KsefPurchaseInvoiceQuery(
            new \DateTimeImmutable('2026-11-01 00:00 UTC'),
            new \DateTimeImmutable('2026-11-12 00:00 UTC'),
        ));

        self::assertSame([], $page->invoices);
        self::assertFalse($page->hasMore);
        self::assertNull($page->permanentStorageHwmDate);
    }

    public function test_it_downloads_the_invoice_xml_unchanged(): void
    {
        $xml = (string) file_get_contents(self::FIXTURES.'invoice-fa3.xml');
        $client = $this->client([new MockResponse($xml, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/xml']])]);

        $downloaded = $client->downloadInvoice($this->session(), '3947150208-20261103-0100A0B1C2D3-4F');

        self::assertSame($xml, $downloaded);
        self::assertSame(['GET https://ksef.example/v2/invoices/ksef/3947150208-20261103-0100A0B1C2D3-4F'], $this->requestLines());
        self::assertSame('application/xml', $this->header(0, 'accept'));
    }

    public function test_it_refreshes_the_access_token_before_it_expires(): void
    {
        $session = $this->session(accessTokenValidUntil: '2026-11-12 22:43:30 UTC');
        $client = $this->client([self::fixture('auth-token-refresh.json'), self::fixture('invoices-query-metadata-empty.json')]);

        $client->queryPurchaseInvoiceMetadata($session, self::anyQuery());

        self::assertSame([
            'POST https://ksef.example/v2/auth/token/refresh',
            'POST https://ksef.example/v2/invoices/query/metadata?sortOrder=Asc&pageOffset=0&pageSize=250',
        ], $this->requestLines());
        self::assertSame('Bearer fixture-refresh-token', $this->header(0, 'authorization'));
        self::assertSame('Bearer fixture-refreshed-access-token', $this->header(1, 'authorization'));
        self::assertSame('fixture-refreshed-access-token', $session->accessToken());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 23:12:49.345678 UTC'), $session->accessTokenValidUntil());
    }

    public function test_it_refreshes_once_when_ksef_no_longer_accepts_the_access_token(): void
    {
        $session = $this->session();
        $client = $this->client([
            new MockResponse('{"title":"Unauthorized","status":401,"detail":"Wymagane jest uwierzytelnienie."}', ['http_code' => 401]),
            self::fixture('auth-token-refresh.json'),
            new MockResponse('<Faktura/>', ['http_code' => 200]),
        ]);

        self::assertSame('<Faktura/>', $client->downloadInvoice($session, '3947150208-20261103-0100A0B1C2D3-4F'));
        self::assertSame('Bearer fixture-refreshed-access-token', $this->header(2, 'authorization'));
        self::assertSame('application/xml', $this->header(2, 'accept'));
    }

    public function test_it_does_not_retry_a_rate_limited_request(): void
    {
        $client = $this->client([self::fixture('error-429.json', 429, ['retry-after' => '30'])]);

        try {
            $client->queryPurchaseInvoiceMetadata($this->session(), self::anyQuery());
            self::fail('Expected the rate limit to be reported.');
        } catch (KsefRateLimitedException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
            self::assertStringContainsString('Przekroczono limit 20 żądań na godzinę', $e->getMessage());
        }
        self::assertCount(1, $this->requests);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:42:47 UTC'), $this->clock->now(), 'no backoff');
    }

    public function test_it_reads_retry_after_given_as_a_date(): void
    {
        $client = $this->client([self::fixture('error-429.json', 429, ['retry-after' => 'Thu, 12 Nov 2026 22:44:17 GMT'])]);

        $this->expectExceptionObject(new KsefRateLimitedException(90, 'Przekroczono limit 20 żądań na godzinę. Spróbuj ponownie po 30 sekundach.'));

        $client->downloadInvoice($this->session(), '3947150208-20261103-0100A0B1C2D3-4F');
    }

    public function test_it_retries_network_errors_and_server_errors_with_backoff(): void
    {
        $client = $this->client([
            self::fixture('error-503.json', 503),
            new MockResponse('', ['error' => 'Connection reset by peer']),
            new MockResponse('', ['http_code' => 502]),
            self::fixture('invoices-query-metadata-empty.json'),
        ]);

        $page = $client->queryPurchaseInvoiceMetadata($this->session(), self::anyQuery());

        self::assertFalse($page->hasMore);
        self::assertCount(4, $this->requests);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:42:54 UTC'), $this->clock->now(), 'backoff of 1 + 2 + 4 seconds');
    }

    public function test_it_reports_ksef_unavailable_after_three_retries(): void
    {
        $client = $this->client(array_fill(0, HttpKsefClient::MAX_RETRIES + 1, self::fixture('error-503.json', 503)));

        try {
            $client->downloadInvoice($this->session(), '3947150208-20261103-0100A0B1C2D3-4F');
            self::fail('Expected KSeF to be reported unavailable.');
        } catch (KsefUnavailableException $e) {
            self::assertStringContainsString('after 4 attempts: HTTP 503 Usługa chwilowo niedostępna.', $e->getMessage());
        }
        self::assertCount(4, $this->requests);
    }

    public function test_it_reports_a_token_without_invoice_read_as_rejected_credentials(): void
    {
        $client = $this->client([self::fixture('error-403.json', 403)]);

        $this->expectException(KsefCredentialsRejectedException::class);
        $this->expectExceptionMessage('Brak wymaganych uprawnień');

        $client->downloadInvoice($this->session(), '3947150208-20261103-0100A0B1C2D3-4F');
    }

    public function test_it_reports_an_unexpected_answer_with_the_ksef_error_codes(): void
    {
        $client = $this->client([self::fixture('error-400.json', 400)]);

        try {
            $client->queryPurchaseInvoiceMetadata($this->session(), self::anyQuery());
            self::fail('Expected the request to fail.');
        } catch (KsefRequestFailedException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame([21405], $e->ksefErrorCodes);
            self::assertStringContainsString('21405 Błąd walidacji danych wejściowych. Zakres dat przekracza 100 dni.', $e->getMessage());
        }
    }

    public function test_it_reports_a_malformed_response(): void
    {
        $client = $this->client([self::json(['hasMore' => true, 'invoices' => []])]);

        $this->expectException(KsefRequestFailedException::class);
        $this->expectExceptionMessage('"isTruncated" is missing or not a boolean');

        $client->queryPurchaseInvoiceMetadata($this->session(), self::anyQuery());
    }

    public function test_it_ends_the_session(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 204])]);

        $client->endSession($this->session());

        self::assertSame(['DELETE https://ksef.example/v2/auth/sessions/current'], $this->requestLines());
        self::assertSame('Bearer fixture-access-token', $this->header(0, 'authorization'));
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): HttpKsefClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = array_shift($responses);
            self::assertNotNull($response, \sprintf('Unexpected request %s %s', $method, $url));

            return $response;
        });

        return new HttpKsefClient($http, $this->clock, $this->cache, self::API_URL);
    }

    private function session(string $accessTokenValidUntil = '2026-11-12 22:57:49 UTC'): KsefSession
    {
        return new KsefSession(
            self::NIP,
            '20261112-AU-2C9EA54000-C4E1F6E27B-6F',
            'fixture-access-token',
            new \DateTimeImmutable($accessTokenValidUntil),
            'fixture-refresh-token',
            new \DateTimeImmutable('2026-11-19 22:42:49 UTC'),
        );
    }

    private static function anyQuery(): KsefPurchaseInvoiceQuery
    {
        return new KsefPurchaseInvoiceQuery(new \DateTimeImmutable('2026-11-01 00:00 UTC'), new \DateTimeImmutable('2026-11-12 00:00 UTC'));
    }

    /** @return list<MockResponse> */
    private static function authenticationAfterKey(): array
    {
        return [
            self::fixture('auth-challenge.json'),
            self::fixture('auth-ksef-token.json', 202),
            self::fixture('auth-status-succeeded.json'),
            self::fixture('auth-token-redeem.json'),
        ];
    }

    /** @param array<string, string> $headers */
    private static function fixture(string $file, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURES.$file), [
            'http_code' => $status,
            'response_headers' => $headers + ['content-type' => 'application/json'],
        ]);
    }

    private static function json(mixed $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * A KsefTokenEncryption certificate from a key pair generated for the test, so that the
     * encrypted token can be decrypted and checked.
     *
     * @return array{RSA\PrivateKey, list<array<string, mixed>>}
     */
    private static function generatedCertificates(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'KSeF fixture'], $key);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 30);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        self::assertTrue(openssl_pkey_export($key, $privatePem));
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
        self::assertIsString($der);

        $privateKey = PublicKeyLoader::loadPrivateKey($privatePem);
        self::assertInstanceOf(RSA\PrivateKey::class, $privateKey);

        return [
            $privateKey->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256'),
            [
                [
                    'certificate' => base64_encode($der),
                    'certificateId' => 'generatedCertificateIdgeneratedCertificate=',
                    'publicKeyId' => 'generatedKeyIdgeneratedKeyIdgeneratedKeyId=',
                    'validFrom' => '2026-11-01T00:00:00+00:00',
                    'validTo' => '2027-11-01T00:00:00+00:00',
                    'usage' => ['KsefTokenEncryption'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function requestLines(): array
    {
        return array_map(static fn (array $request): string => $request['method'].' '.$request['url'], $this->requests);
    }

    /** @return array<array-key, mixed> */
    private function jsonBody(int $index): array
    {
        $body = $this->requests[$index]['options']['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function header(int $index, string $name): ?string
    {
        $headers = $this->requests[$index]['options']['normalized_headers'][$name] ?? null;
        if (!\is_array($headers) || !\is_string($headers[0] ?? null)) {
            return null;
        }

        return substr($headers[0], \strlen($name) + 2);
    }
}
