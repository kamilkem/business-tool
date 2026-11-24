<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Http;

use App\Ksef\Domain\Exception\KsefCredentialsRejectedException;
use App\Ksef\Domain\Exception\KsefRateLimitedException;
use App\Ksef\Domain\Exception\KsefRequestFailedException;
use App\Ksef\Domain\Exception\KsefUnavailableException;
use App\Ksef\Domain\KsefClientInterface;
use App\Ksef\Domain\Model\KsefFormCode;
use App\Ksef\Domain\Model\KsefInvoiceMetadata;
use App\Ksef\Domain\Model\KsefInvoiceMetadataPage;
use App\Ksef\Domain\Model\KsefPurchaseInvoiceQuery;
use App\Ksef\Domain\Model\KsefSession;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Exception\NoKeyLoadedException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * KSeF API 2.0 over the scoped `ksef.client` (ADR 0023 §1, ADR 0024 §3). Endpoints and payloads follow
 * the official OpenAPI (https://api.ksef.mf.gov.pl/docs/v2/openapi.json, API 2.9.0) and the
 * integrator guide (https://github.com/CIRFMF/ksef-docs: uwierzytelnianie.md, auth/sesje.md,
 * pobieranie-faktur/, limity/limity-api.md).
 *
 * Every request except the public auth challenge first waits for room within KSeF's API limits
 * (KsefRequestPacer, KsefApiLimit). Network errors and 5xx answers are retried up to 3 times with
 * exponential backoff; a 429 is never retried (ADR 0023 §5). Access and refresh tokens stay in the
 * KsefSession in memory.
 */
final readonly class HttpKsefClient implements KsefClientInterface
{
    public const int MAX_RETRIES = 3;
    public const float FIRST_BACKOFF_SECONDS = 1.0;
    public const int MAX_STATUS_POLLS = 30;
    public const float STATUS_POLL_INTERVAL_SECONDS = 1.0;
    /** The access token is refreshed when it expires within this margin. */
    public const int REFRESH_MARGIN_SECONDS = 60;

    private const string TOKEN_ENCRYPTION_USAGE = 'KsefTokenEncryption';

    /** Statuses of `GET /auth/{referenceNumber}` (OpenAPI, AuthenticationOperationStatusResponse). */
    private const int AUTH_IN_PROGRESS = 100;
    private const int AUTH_SUCCEEDED = 200;
    /** Unknown error and "cancelled by the system, try again": not the credentials' fault. */
    private const array AUTH_TRANSIENT_FAILURES = [500, 550];

    public function __construct(
        private HttpClientInterface $ksefClient,
        private ClockInterface $clock,
        private CacheInterface $cache,
        private KsefRequestPacer $pacer,
        #[Autowire(env: 'KSEF_API_URL')]
        private string $apiUrl,
    ) {
    }

    public function authenticateWithKsefToken(string $nip, #[\SensitiveParameter] string $ksefToken): KsefSession
    {
        $key = $this->tokenEncryptionKey();

        $challenge = $this->json('auth challenge', $this->send('auth challenge', 'POST', 'auth/challenge', limit: null), 200);
        $timestampMs = self::int($challenge, 'timestampMs', 'auth challenge');

        $init = $this->json('KSeF token authentication', $this->send('KSeF token authentication', 'POST', 'auth/ksef-token', [
            'json' => [
                'challenge' => self::string($challenge, 'challenge', 'auth challenge'),
                'contextIdentifier' => ['type' => 'Nip', 'value' => $nip],
                'encryptedToken' => self::encryptToken($key['key'], $ksefToken, $timestampMs),
                'publicKeyId' => $key['publicKeyId'],
            ],
        ], context: $nip), 202);
        $referenceNumber = self::string($init, 'referenceNumber', 'KSeF token authentication');
        $authenticationToken = self::string(self::object($init, 'authenticationToken', 'KSeF token authentication'), 'token', 'KSeF token authentication');

        $this->waitForAuthentication($nip, $referenceNumber, $authenticationToken);

        $tokens = $this->json('token redemption', $this->send('token redemption', 'POST', 'auth/token/redeem', [
            'auth_bearer' => $authenticationToken,
        ], context: $nip), 200);
        $access = self::object($tokens, 'accessToken', 'token redemption');
        $refresh = self::object($tokens, 'refreshToken', 'token redemption');

        return new KsefSession(
            $nip,
            $referenceNumber,
            self::string($access, 'token', 'token redemption'),
            self::date($access, 'validUntil', 'token redemption'),
            self::string($refresh, 'token', 'token redemption'),
            self::date($refresh, 'validUntil', 'token redemption'),
        );
    }

    public function queryPurchaseInvoiceMetadata(KsefSession $session, KsefPurchaseInvoiceQuery $query): KsefInvoiceMetadataPage
    {
        $what = 'invoice metadata query';
        $page = $this->json($what, $this->sendAuthorized($session, $what, 'POST', 'invoices/query/metadata', [
            'query' => [
                'sortOrder' => 'Asc',
                'pageOffset' => $query->pageOffset,
                'pageSize' => KsefPurchaseInvoiceQuery::PAGE_SIZE,
            ],
            'json' => [
                'subjectType' => 'Subject2',
                'dateRange' => [
                    'dateType' => 'PermanentStorage',
                    'from' => $query->from->format('Y-m-d\TH:i:s.vP'),
                    'to' => $query->to->format('Y-m-d\TH:i:s.vP'),
                    'restrictToPermanentStorageHwmDate' => true,
                ],
            ],
        ], KsefApiLimit::InvoiceMetadataQuery), 200);

        $invoices = [];
        foreach (self::list($page, 'invoices', $what) as $record) {
            if (!\is_array($record)) {
                throw KsefRequestFailedException::malformedResponse($what, 'an invoice record is not an object');
            }
            $formCode = self::object($record, 'formCode', $what);
            $invoices[] = new KsefInvoiceMetadata(
                self::string($record, 'ksefNumber', $what),
                new KsefFormCode(
                    self::string($formCode, 'systemCode', $what),
                    self::string($formCode, 'schemaVersion', $what),
                    self::string($formCode, 'value', $what),
                ),
                self::string($record, 'invoiceHash', $what),
                self::date($record, 'permanentStorageDate', $what),
                self::businessDate($record, 'issueDate', $what),
            );
        }

        return new KsefInvoiceMetadataPage(
            $invoices,
            self::bool($page, 'hasMore', $what),
            self::bool($page, 'isTruncated', $what),
            null === ($page['permanentStorageHwmDate'] ?? null) ? null : self::date($page, 'permanentStorageHwmDate', $what),
        );
    }

    public function downloadInvoice(KsefSession $session, string $ksefNumber): string
    {
        $what = 'invoice download';
        $response = $this->sendAuthorized($session, $what, 'GET', 'invoices/ksef/'.rawurlencode($ksefNumber), [
            'headers' => ['Accept' => 'application/xml'],
        ], KsefApiLimit::InvoiceDownload);
        $this->expectStatus($what, $response, 200);

        $xml = $response->getContent(false);
        if ('' === $xml) {
            throw KsefRequestFailedException::malformedResponse($what, 'the body is empty');
        }

        return $xml;
    }

    public function endSession(KsefSession $session): void
    {
        $what = 'session revocation';
        $this->expectStatus($what, $this->sendAuthorized($session, $what, 'DELETE', 'auth/sessions/current'), 204);
    }

    /** Polls the authentication status until it succeeds, fails or the polling budget is spent. */
    private function waitForAuthentication(string $nip, string $referenceNumber, #[\SensitiveParameter] string $authenticationToken): void
    {
        $what = 'authentication status';
        for ($poll = 1; $poll <= self::MAX_STATUS_POLLS; ++$poll) {
            $body = $this->json($what, $this->send($what, 'GET', 'auth/'.rawurlencode($referenceNumber), [
                'auth_bearer' => $authenticationToken,
            ], context: $nip), 200);
            $status = self::object($body, 'status', $what);
            $code = self::int($status, 'code', $what);

            if (self::AUTH_SUCCEEDED === $code) {
                return;
            }
            if (self::AUTH_IN_PROGRESS !== $code) {
                $description = self::string($status, 'description', $what);
                $details = self::strings($status['details'] ?? []);

                throw \in_array($code, self::AUTH_TRANSIENT_FAILURES, true)
                    ? KsefUnavailableException::authenticationCancelled($code, $description, $details)
                    : KsefCredentialsRejectedException::authenticationFailed($code, $description, $details);
            }
            if ($poll < self::MAX_STATUS_POLLS) {
                $this->clock->sleep(self::STATUS_POLL_INTERVAL_SECONDS);
            }
        }

        throw KsefUnavailableException::authenticationNotFinished(self::MAX_STATUS_POLLS);
    }

    /**
     * A call with the session's access token: refreshed first when it is about to expire, and once
     * more if KSeF answers 401 anyway.
     *
     * @param array<string, mixed> $options
     *
     * @throws KsefRateLimitedException
     */
    private function sendAuthorized(KsefSession $session, string $what, string $method, string $path, array $options = [], KsefApiLimit $limit = KsefApiLimit::Other): ResponseInterface
    {
        $refreshAt = $this->clock->now()->modify(\sprintf('+%d seconds', self::REFRESH_MARGIN_SECONDS));
        if ($session->accessTokenValidUntil() <= $refreshAt) {
            $this->refresh($session);
        }

        $response = $this->send($what, $method, $path, $options + ['auth_bearer' => $session->accessToken()], $limit, $session->contextNip);
        if (401 === $response->getStatusCode()) {
            $this->refresh($session);
            $response = $this->send($what, $method, $path, ['auth_bearer' => $session->accessToken()] + $options, $limit, $session->contextNip);
        }

        return $response;
    }

    private function refresh(KsefSession $session): void
    {
        $what = 'access token refresh';
        $body = $this->json($what, $this->send($what, 'POST', 'auth/token/refresh', [
            'auth_bearer' => $session->refreshToken(),
        ], context: $session->contextNip), 200);
        $access = self::object($body, 'accessToken', $what);

        $session->replaceAccessToken(self::string($access, 'token', $what), self::date($access, 'validUntil', $what));
    }

    /**
     * KSeF's public key for KSeF token encryption, cached until the certificate's `validTo`.
     *
     * @return array{key: RSA\PublicKey, publicKeyId: string}
     */
    private function tokenEncryptionKey(): array
    {
        /** @var array{certificate: string, publicKeyId: string} $certificate */
        $certificate = $this->cache->get('ksef_token_encryption_key_'.hash('xxh128', $this->apiUrl), function (ItemInterface $item): array {
            $certificate = $this->fetchTokenEncryptionCertificate();
            $item->expiresAt($certificate['validTo']);

            return ['certificate' => $certificate['certificate'], 'publicKeyId' => $certificate['publicKeyId']];
        });

        $der = base64_decode($certificate['certificate'], true);
        try {
            $key = false === $der ? null : PublicKeyLoader::loadPublicKey($der);
        } catch (NoKeyLoadedException) {
            $key = null;
        }
        if (!$key instanceof RSA\PublicKey) {
            throw KsefRequestFailedException::malformedResponse('public key certificates', 'the KsefTokenEncryption certificate holds no RSA key');
        }

        return [
            // RSA-OAEP with SHA-256 and MGF1-SHA-256 (uwierzytelnianie.md, "Uwierzytelnianie tokenem KSeF").
            'key' => $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256'),
            'publicKeyId' => $certificate['publicKeyId'],
        ];
    }

    /** `token|timestampMs`, encrypted and Base64-encoded (`POST /auth/ksef-token`, uwierzytelnianie.md). */
    private static function encryptToken(RSA\PublicKey $key, #[\SensitiveParameter] string $ksefToken, int $timestampMs): string
    {
        $encrypted = $key->encrypt($ksefToken.'|'.$timestampMs);
        if (!\is_string($encrypted)) {
            throw KsefRequestFailedException::malformedResponse('public key certificates', 'the KsefTokenEncryption key cannot encrypt the token');
        }

        return base64_encode($encrypted);
    }

    /** @return array{certificate: string, publicKeyId: string, validTo: \DateTimeImmutable} */
    private function fetchTokenEncryptionCertificate(): array
    {
        $what = 'public key certificates';
        $certificates = $this->json($what, $this->send($what, 'GET', 'security/public-key-certificates'), 200);
        $now = $this->clock->now();

        $chosen = null;
        foreach ($certificates as $certificate) {
            if (!\is_array($certificate) || !\in_array(self::TOKEN_ENCRYPTION_USAGE, self::list($certificate, 'usage', $what), true)) {
                continue;
            }
            $validFrom = self::date($certificate, 'validFrom', $what);
            $validTo = self::date($certificate, 'validTo', $what);
            if ($validFrom > $now || $validTo <= $now || (null !== $chosen && $chosen['validFrom'] >= $validFrom)) {
                continue;
            }
            $chosen = [
                'certificate' => self::string($certificate, 'certificate', $what),
                'publicKeyId' => self::string($certificate, 'publicKeyId', $what),
                'validFrom' => $validFrom,
                'validTo' => $validTo,
            ];
        }

        if (null === $chosen) {
            throw KsefRequestFailedException::noTokenEncryptionKey();
        }

        return ['certificate' => $chosen['certificate'], 'publicKeyId' => $chosen['publicKeyId'], 'validTo' => $chosen['validTo']];
    }

    /**
     * Sends a request, retrying network errors and 5xx answers; the body is read inside the retry
     * loop so that a connection dropped mid-body is retried too. Every attempt is paced within
     * `$limit` for `$context` (the NIP; none for public endpoints), and `$what` names the endpoint's
     * counter. A 429 throws at once.
     *
     * @param array<string, mixed> $options
     *
     * @throws KsefRateLimitedException
     */
    private function send(string $what, string $method, string $path, array $options = [], ?KsefApiLimit $limit = KsefApiLimit::Other, string $context = ''): ResponseInterface
    {
        $options['headers'] = ($options['headers'] ?? []) + [
            'Accept' => 'application/json',
            // Errors as RFC 9457 problem details (every operation's description in the OpenAPI).
            'X-Error-Format' => 'problem-details',
        ];
        $url = rtrim($this->apiUrl, '/').'/'.$path;

        for ($attempt = 1; ; ++$attempt) {
            if (null !== $limit) {
                $this->pacer->pace($context, $what, $limit);
            }
            try {
                $response = $this->ksefClient->request($method, $url, $options);
                $status = $response->getStatusCode();
                $response->getContent(false);

                if (429 === $status) {
                    throw new KsefRateLimitedException($this->retryAfterSeconds($response), self::problem($response)['detail']);
                }
                if ($status < 500) {
                    return $response;
                }
                $lastError = \sprintf('HTTP %d %s', $status, self::problem($response)['detail']);
            } catch (HttpClientExceptionInterface $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt > self::MAX_RETRIES) {
                throw KsefUnavailableException::afterRetries($what, $attempt, $lastError);
            }
            $this->clock->sleep(self::FIRST_BACKOFF_SECONDS * 2 ** ($attempt - 1));
        }
    }

    /** @return array<array-key, mixed> */
    private function json(string $what, ResponseInterface $response, int $expectedStatus): array
    {
        $this->expectStatus($what, $response, $expectedStatus);

        try {
            return $response->toArray(false);
        } catch (DecodingExceptionInterface $e) {
            throw KsefRequestFailedException::malformedResponse($what, $e->getMessage());
        }
    }

    private function expectStatus(string $what, ResponseInterface $response, int $expectedStatus): void
    {
        $status = $response->getStatusCode();
        if ($expectedStatus === $status) {
            return;
        }

        $problem = self::problem($response);
        if (403 === $status) {
            throw KsefCredentialsRejectedException::missingPermission($problem['detail']);
        }

        throw KsefRequestFailedException::unexpectedStatus($what, $status, $problem['detail'], $problem['codes']);
    }

    /**
     * The message and KSeF error codes of an error answer: problem details (`detail`, `errors[]`) or
     * the deprecated `exception.exceptionDetailList[]` / `status.details[]` shapes.
     *
     * @return array{detail: string, codes: list<int>}
     */
    private static function problem(ResponseInterface $response): array
    {
        try {
            $body = json_decode($response->getContent(false), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException|HttpClientExceptionInterface) {
            return ['detail' => '', 'codes' => []];
        }
        if (!\is_array($body)) {
            return ['detail' => '', 'codes' => []];
        }

        $messages = \is_string($body['detail'] ?? null) ? [$body['detail']] : [];
        $codes = [];
        $errors = $body['errors'] ?? $body['exception']['exceptionDetailList'] ?? [];
        foreach (\is_array($errors) ? $errors : [] as $error) {
            if (!\is_array($error)) {
                continue;
            }
            $code = $error['code'] ?? $error['exceptionCode'] ?? null;
            if (\is_int($code)) {
                $codes[] = $code;
            }
            $description = $error['description'] ?? $error['exceptionDescription'] ?? null;
            $messages[] = trim((\is_int($code) ? $code.' ' : '').(\is_string($description) ? $description : '').' '.implode('; ', self::strings($error['details'] ?? [])));
        }
        if (\is_array($body['status'] ?? null)) {
            $messages = [...$messages, ...self::strings($body['status']['details'] ?? [])];
        }

        return ['detail' => implode(' | ', array_filter($messages)), 'codes' => $codes];
    }

    /** `Retry-After` in seconds (limity/limity-api.md), or an HTTP date as a fallback. */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $value = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (null === $value) {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $date = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value);

        return false === $date ? null : max(0, $date->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function object(array $data, string $key, string $what): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is missing or not an object', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $key, string $what): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is missing or not a list', $key));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key, string $what): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is missing or not a string', $key));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function int(array $data, string $key, string $what): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is missing or not an integer', $key));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function bool(array $data, string $key, string $what): bool
    {
        $value = $data[$key] ?? null;
        if (!\is_bool($value)) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is missing or not a boolean', $key));
        }

        return $value;
    }

    /**
     * An ISO 8601 instant in UTC. KSeF sends up to seven fraction digits; PHP keeps six.
     *
     * @param array<array-key, mixed> $data
     */
    private static function date(array $data, string $key, string $what): \DateTimeImmutable
    {
        $value = self::string($data, $key, $what);
        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is not a date: "%s"', $key, $value));
        }
    }

    /**
     * A `YYYY-MM-DD` date of the response.
     *
     * @param array<array-key, mixed> $data
     */
    private static function businessDate(array $data, string $key, string $what): BusinessDate
    {
        $value = self::string($data, $key, $what);
        try {
            return BusinessDate::fromString($value);
        } catch (InvalidBusinessDateException) {
            throw KsefRequestFailedException::malformedResponse($what, \sprintf('"%s" is not a date: "%s"', $key, $value));
        }
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
