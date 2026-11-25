<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\Model\KsefInvoiceExportState;
use App\Ksef\Domain\Model\KsefInvoicePackage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Ksef\Infrastructure\Http\HttpKsefClient;
use App\Ksef\Infrastructure\Http\KsefExportKey;
use App\Ksef\Infrastructure\Http\KsefRequestPacer;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** The secret, NIP and key id the test package was built with (Fixtures/Ksef/export-package/build.php). */
    private const string EXPORT_KEY_SECRET = 'fixture-export-key-secret';
    private const string EXPORT_KEY_ID = '019ab6c0-0000-7000-8000-000000000039';
    private const string EXPORT_REFERENCE = '20261125-EH-2D2C11B000-E9C9ED8340-EE';
    private const string PACKAGE = self::FIXTURES.'export-package/'.self::EXPORT_REFERENCE;

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

    public function test_it_paces_invoice_downloads_within_ksef_limits_per_second_and_minute(): void
    {
        $client = $this->client(array_map(static fn (): MockResponse => self::xml(), range(1, 17)));
        $session = $this->session('2026-11-12 23:57:49 UTC');

        foreach (range(1, 17) as $download) {
            $client->downloadInvoice($session, '3947150208-20261103-0100A0B1C2D3-4F');
        }

        self::assertCount(17, $this->requests);
        // The 9th waits for the second to pass, the 17th for the minute (KsefRequestPacer::MARGIN_SECONDS each).
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:43:47.25 UTC'), $this->clock->now());
    }

    public function test_it_stops_before_the_hourly_limit_without_calling_ksef(): void
    {
        $client = $this->client(array_map(static fn (): MockResponse => self::xml(), range(1, 64)));
        $session = $this->session('2026-11-13 23:57:49 UTC');
        $start = $this->clock->now();
        foreach (range(1, 64) as $download) {
            $client->downloadInvoice($session, '3947150208-20261103-0100A0B1C2D3-4F');
        }
        $after64 = $this->clock->now();

        try {
            $client->downloadInvoice($session, '3947150208-20261103-0100A0B1C2D3-4F');
            self::fail('Expected the run to stop before the hourly limit.');
        } catch (KsefRateLimitedException $e) {
            self::assertSame("Paused before KSeF's limit of 64 invoice download requests per hour; the import continues after {$e->retryAfterSeconds} s.", $e->getMessage());
            // The first request leaves the hour 3600.25 s after it was sent.
            self::assertSame((int) ceil(3600.25 - ((float) $after64->format('U.u') - (float) $start->format('U.u'))), $e->retryAfterSeconds);
        }
        self::assertCount(64, $this->requests, 'The 65th request is never sent.');
        self::assertEquals($after64, $this->clock->now(), 'No waiting for the hour.');
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

    public function test_it_requests_a_purchase_invoice_export_with_its_key_encrypted_for_ksef(): void
    {
        [$privateKey, $certificates] = self::generatedCertificates('SymmetricKeyEncryption');
        $client = $this->client([self::json($certificates), self::fixture('invoices-export.json', 201)]);

        $reference = $client->startPurchaseInvoiceExport($this->session(), self::anyQuery(), self::EXPORT_KEY_ID);

        self::assertSame(self::EXPORT_REFERENCE, $reference);
        self::assertSame(['GET https://ksef.example/v2/security/public-key-certificates', 'POST https://ksef.example/v2/invoices/exports'], $this->requestLines());
        self::assertSame('Bearer fixture-access-token', $this->header(1, 'authorization'));
        $body = $this->jsonBody(1);
        self::assertSame([
            'subjectType' => 'Subject2',
            'dateRange' => [
                'dateType' => 'PermanentStorage',
                'from' => '2026-11-01T00:00:00.000+00:00',
                'to' => '2026-11-12T00:00:00.000+00:00',
                'restrictToPermanentStorageHwmDate' => true,
            ],
        ], $body['filters']);
        self::assertSame('Zip', $body['compressionType']);

        $expected = KsefExportKey::derive(self::EXPORT_KEY_SECRET, self::NIP, self::EXPORT_KEY_ID);
        $encryption = $body['encryption'];
        self::assertIsArray($encryption);
        self::assertSame('generatedKeyIdgeneratedKeyIdgeneratedKeyId=', $encryption['publicKeyId']);
        self::assertSame($expected->key, $privateKey->decrypt((string) base64_decode((string) $encryption['encryptedSymmetricKey'], true)));
        self::assertSame(base64_encode($expected->iv), $encryption['initializationVector']);
        self::assertSame(32, \strlen($expected->key));
        self::assertSame(16, \strlen($expected->iv));
    }

    public function test_it_encrypts_the_export_key_with_the_symmetric_key_encryption_key(): void
    {
        $client = $this->client([self::fixture('public-key-certificates.json'), self::fixture('invoices-export.json', 201)]);

        $client->startPurchaseInvoiceExport($this->session(), self::anyQuery(), self::EXPORT_KEY_ID);

        $encryption = $this->jsonBody(1)['encryption'];
        self::assertIsArray($encryption);
        self::assertNotSame('IPbPM4CB49vtoR/x/3fEI+Y+Q6lK/bVVehQ7/NlPJoo=', $encryption['publicKeyId'], 'not the KsefTokenEncryption key');
        self::assertSame(256, \strlen((string) base64_decode((string) $encryption['encryptedSymmetricKey'], true)), 'one RSA-2048 block');
    }

    public function test_it_derives_another_key_for_another_key_id_or_nip(): void
    {
        $key = KsefExportKey::derive(self::EXPORT_KEY_SECRET, self::NIP, self::EXPORT_KEY_ID);

        self::assertNotSame($key->key, KsefExportKey::derive(self::EXPORT_KEY_SECRET, self::NIP, '019ab6c0-0000-7000-8000-000000000040')->key);
        self::assertNotSame($key->key, KsefExportKey::derive(self::EXPORT_KEY_SECRET, '1111111111', self::EXPORT_KEY_ID)->key);
        self::assertNotSame($key->key, KsefExportKey::derive('another-secret', self::NIP, self::EXPORT_KEY_ID)->key);
        self::assertEquals($key, KsefExportKey::derive(self::EXPORT_KEY_SECRET, self::NIP, self::EXPORT_KEY_ID));
    }

    public function test_it_waits_for_the_export_while_ksef_builds_the_package(): void
    {
        $client = $this->client([
            self::fixture('invoices-export-status-in-progress.json'),
            self::fixture('invoices-export-status-in-progress.json'),
            self::fixture('invoices-export-status-succeeded.json'),
        ]);

        $status = $client->getPurchaseInvoiceExport($this->session(), self::EXPORT_REFERENCE, 60);

        self::assertSame(KsefInvoiceExportState::Ready, $status->state);
        self::assertSame(array_fill(0, 3, 'GET https://ksef.example/v2/invoices/exports/'.self::EXPORT_REFERENCE), $this->requestLines());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 22:42:57 UTC'), $this->clock->now(), 'two poll intervals of 5 seconds');
        $package = $status->package;
        self::assertNotNull($package);
        self::assertSame(2, $package->invoiceCount);
        self::assertSame([1, 2], array_map(static fn ($part): int => $part->ordinalNumber, $package->parts), 'sorted by their ordinal number');
        self::assertFalse($package->isTruncated);
        self::assertNull($package->lastPermanentStorageDate);
        self::assertEquals(new \DateTimeImmutable('2026-11-25 21:30:00.123456 UTC'), $package->permanentStorageHwmDate);
    }

    public function test_it_leaves_an_export_in_progress_once_the_wait_is_over(): void
    {
        $client = $this->client([self::fixture('invoices-export-status-in-progress.json')]);

        $status = $client->getPurchaseInvoiceExport($this->session(), self::EXPORT_REFERENCE, 0);

        self::assertSame(KsefInvoiceExportState::InProgress, $status->state);
        self::assertCount(1, $this->requests);
    }

    public function test_it_reads_a_truncated_package_and_where_the_next_export_starts(): void
    {
        $client = $this->client([self::fixture('invoices-export-status-truncated.json')]);

        $package = $client->getPurchaseInvoiceExport($this->session(), self::EXPORT_REFERENCE, 0)->package;

        self::assertNotNull($package);
        self::assertTrue($package->isTruncated);
        self::assertEquals(new \DateTimeImmutable('2026-09-11 11:40:40.266578 UTC'), $package->lastPermanentStorageDate);
    }

    /** @return iterable<string, array{MockResponse, KsefInvoiceExportState, bool}> */
    public static function exportOutcomes(): iterable
    {
        yield 'expired (210)' => [self::fixture('invoices-export-status-expired.json'), KsefInvoiceExportState::Expired, false];
        yield 'no longer kept (410 Gone)' => [self::fixture('error-400.json', 410), KsefInvoiceExportState::Expired, false];
        yield 'past the high-water mark (420)' => [self::fixture('invoices-export-status-beyond-hwm.json'), KsefInvoiceExportState::NothingToExport, false];
        yield 'cancelled by the system (550)' => [self::fixture('invoices-export-status-cancelled.json'), KsefInvoiceExportState::Failed, true];
        yield 'the key does not decrypt (415)' => [self::json(['status' => ['code' => 415, 'description' => 'Błąd odszyfrowania dostarczonego klucza']]), KsefInvoiceExportState::Failed, false];
    }

    #[DataProvider('exportOutcomes')]
    public function test_it_reports_what_became_of_an_export(MockResponse $response, KsefInvoiceExportState $state, bool $transient): void
    {
        $client = $this->client([$response]);

        $status = $client->getPurchaseInvoiceExport($this->session(), self::EXPORT_REFERENCE, 60);

        self::assertSame($state, $status->state);
        self::assertSame($transient, $status->transient);
        self::assertCount(1, $this->requests, 'no waiting for an outcome');
    }

    public function test_it_downloads_decrypts_and_opens_the_export_package(): void
    {
        $package = $this->readyPackage();
        $client = $this->client([self::part(1), self::part(2)]);

        $contents = $client->openInvoicePackage($this->session(), self::EXPORT_KEY_ID, $package);

        self::assertSame([
            'GET https://ksef-storage.example/invoice-part/'.self::EXPORT_REFERENCE.'-001.zip.aes?sig=fixture-signature',
            'GET https://ksef-storage.example/invoice-part/'.self::EXPORT_REFERENCE.'-002.zip.aes?sig=fixture-signature',
        ], $this->requestLines());
        self::assertNull($this->header(0, 'authorization'), 'part links need no access token');
        $invoices = $contents->invoices();
        self::assertSame(['3947150208-20261103-0100A0B1C2D3-4F', '5218630497-20261104-0100E5F6A7B8-1C'], array_map(static fn ($metadata): string => $metadata->ksefNumber, $invoices), 'by permanent-storage date');
        self::assertSame('2026-11-03', $invoices[0]->issueDate->toString());
        self::assertTrue($invoices[0]->formCode->isFa3());
        $xml = $contents->xmlOf('3947150208-20261103-0100A0B1C2D3-4F');
        self::assertSame(file_get_contents(self::FIXTURES.'invoice-fa3.xml'), $xml);
        self::assertSame($invoices[0]->invoiceHash, base64_encode(hash('sha256', $xml, true)));
        self::assertSame(file_get_contents(self::FIXTURES.'Fa3/vat-several-rates.xml'), $contents->xmlOf('5218630497-20261104-0100E5F6A7B8-1C'));

        $contents->close();
        $this->expectException(KsefRequestFailedException::class);
        $contents->xmlOf('3947150208-20261103-0100A0B1C2D3-4F');
    }

    public function test_it_refuses_a_package_part_that_does_not_match_its_hash(): void
    {
        $package = $this->readyPackage();
        $client = $this->client([new MockResponse(((string) file_get_contents(self::PACKAGE.'-001.zip.aes')).'x')]);

        $this->expectException(KsefRequestFailedException::class);
        $this->expectExceptionMessage('part 1 does not match its encrypted SHA-256 hash');

        $client->openInvoicePackage($this->session(), self::EXPORT_KEY_ID, $package);
    }

    public function test_it_refuses_a_package_it_cannot_decrypt_with_its_key(): void
    {
        $package = $this->readyPackage();
        $client = $this->client([self::part(1), self::part(2)]);

        $this->expectException(KsefRequestFailedException::class);
        $this->expectExceptionMessageMatches('/part 1 does not (decrypt|match its SHA-256 hash)/');

        $client->openInvoicePackage($this->session(), '019ab6c0-0000-7000-8000-000000000040', $package);
    }

    public function test_it_reports_a_package_without_an_invoice_it_lists(): void
    {
        $client = $this->client([self::part(1), self::part(2)]);
        $contents = $client->openInvoicePackage($this->session(), self::EXPORT_KEY_ID, $this->readyPackage());

        try {
            $this->expectException(KsefRequestFailedException::class);
            $this->expectExceptionMessage('it holds no XML for 9999999999-20261105-000000000000-00');

            $contents->xmlOf('9999999999-20261105-000000000000-00');
        } finally {
            $contents->close();
        }
    }

    public function test_it_retries_a_part_download_that_fails_on_the_network(): void
    {
        $package = $this->readyPackage();
        $client = $this->client([new MockResponse('', ['http_code' => 503]), self::part(1), self::part(2)]);

        $contents = $client->openInvoicePackage($this->session(), self::EXPORT_KEY_ID, $package);
        $contents->close();

        self::assertCount(3, $this->requests);
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

        return new HttpKsefClient($http, $this->clock, $this->cache, new KsefRequestPacer($this->clock, $this->cache), self::API_URL, self::EXPORT_KEY_SECRET);
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

    /** The finished export of the test package, read from its status fixture. */
    private function readyPackage(): KsefInvoicePackage
    {
        $requests = $this->requests;
        $package = $this->client([self::fixture('invoices-export-status-succeeded.json')])->getPurchaseInvoiceExport($this->session(), self::EXPORT_REFERENCE, 0)->package;
        self::assertNotNull($package);
        $this->requests = $requests;

        return $package;
    }

    private static function part(int $ordinal): MockResponse
    {
        return new MockResponse((string) file_get_contents(\sprintf('%s-%03d.zip.aes', self::PACKAGE, $ordinal)), ['response_headers' => ['content-type' => 'application/octet-stream']]);
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

    private static function xml(): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURES.'invoice-fa3.xml'), ['response_headers' => ['content-type' => 'application/xml']]);
    }

    private static function json(mixed $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * A certificate (KsefTokenEncryption by default) from a key pair generated for the test, so that
     * what was encrypted with it can be decrypted and checked.
     *
     * @return array{RSA\PrivateKey, list<array<string, mixed>>}
     */
    private static function generatedCertificates(string $usage = 'KsefTokenEncryption'): array
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
                    'usage' => [$usage],
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
