<?php

declare(strict_types=1);

namespace Ui\Http\Ksef;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Ksef\Application\Query\GetKsefConnection\KsefConnectionView;
use Ui\Http\Ksef\Processor\RemoveKsefTokenProcessor;
use Ui\Http\Ksef\Processor\SaveKsefTokenProcessor;
use Ui\Http\Ksef\Processor\TestKsefConnectionProcessor;
use Ui\Http\Ksef\Provider\KsefConnectionProvider;

/**
 * The KSeF connection of the organization selected by the X-Organization-Id header (ADR 0023 §3):
 * whether a KSeF token is saved and works, and the state of the purchase invoice import. The token is
 * write-only: no response ever contains it (ADR 0026 §5). Organizations in Poland with a valid NIP only.
 */
#[ApiResource(
    shortName: 'KsefConnection',
    operations: [
        new Get(
            uriTemplate: '/ksef-connection',
            openapi: new Operation(
                summary: 'The KSeF connection of the selected organization, without its token.',
                description: 'Owners and managers only (403 otherwise). 404 while no KSeF token was ever saved.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The organization has no KSeF connection yet.'),
                ],
            ),
            provider: KsefConnectionProvider::class,
        ),
        new Post(
            uriTemplate: '/ksef-connection/token',
            status: 200,
            openapi: new Operation(
                summary: 'Saves or replaces the KSeF token of the selected organization and checks it against KSeF.',
                description: 'Owners only (403 otherwise). The organization\'s country must be PL and its tax ID a valid NIP (422). The token is stored encrypted, creates the connection on first use and makes it active; then it is checked against KSeF as by POST /ksef-connection/test, and the response shows the outcome (status, verifiedAt, lastCheckError). A token KSeF refuses is still saved, with the status credentials_rejected. The token never appears in a response.',
                responses: [
                    '200' => new Response('The connection after saving and checking the token.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '422' => new Response('The token is missing or blank, or KSeF is not available to the organization (country other than PL, or no valid NIP).'),
                ],
            ),
            input: SaveKsefTokenInput::class,
            read: false,
            processor: SaveKsefTokenProcessor::class,
        ),
        new Delete(
            uriTemplate: '/ksef-connection/token',
            openapi: new Operation(
                summary: 'Removes the KSeF token of the selected organization.',
                description: 'Owners only (403 otherwise). The stored token is deleted and the connection becomes disabled: nothing is imported until a new token is saved. The import\'s progress is kept. 404 when no token is saved.',
                responses: [
                    '204' => new Response('The token was removed.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The organization has no KSeF token saved.'),
                ],
            ),
            read: false,
            processor: RemoveKsefTokenProcessor::class,
        ),
        new Post(
            uriTemplate: '/ksef-connection/test',
            status: 200,
            openapi: new Operation(
                summary: 'Checks the saved KSeF token against KSeF.',
                description: 'Owners and managers only (403 otherwise). Authenticates with the token, asks KSeF for one day of purchase invoice metadata and ends the session; nothing is imported. The outcome is in the response, not in the status code: lastCheckedAt, lastCheckError and verifiedAt; a token KSeF refuses, or one that can no longer be decrypted, makes the status credentials_rejected. The request has no body. 404 when no token was ever saved, 409 when it was removed, 422 when KSeF is not available to the organization.',
                responses: [
                    '200' => new Response('The connection with the outcome of the check.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The organization has no KSeF connection yet.'),
                    '409' => new Response('The KSeF token was removed.'),
                    '422' => new Response('KSeF is not available to the organization (country other than PL, or no valid NIP).'),
                ],
            ),
            input: false,
            read: false,
            processor: TestKsefConnectionProcessor::class,
        ),
    ],
)]
final readonly class KsefConnectionResource
{
    public function __construct(
        public string $id,
        /** `active`: the import runs; `credentials_rejected`: KSeF refused the token or it no longer decrypts, save a new one; `disabled`: no token. */
        public string $status,
        /** Whether a KSeF token is saved. The token itself is never returned. */
        public bool $tokenSet,
        /** When the current token was saved; null when none is. */
        public ?\DateTimeImmutable $tokenSetAt,
        /** The organization's NIP KSeF is used with; null when its tax ID is not a valid NIP. */
        public ?string $nip,
        /** When KSeF last accepted the current token (a check or an import); null until then. */
        public ?\DateTimeImmutable $verifiedAt,
        /** When the current token was last checked. */
        public ?\DateTimeImmutable $lastCheckedAt,
        /** Why the last check failed (`credentials_rejected`, `token_unreadable`, `rate_limited`, `unavailable`, `request_failed`); null when it succeeded or none ran. */
        public ?string $lastCheckError,
        public ?string $lastCheckErrorMessage,
        /** Invoices stored in KSeF before this instant are imported; null before the first import. */
        public ?\DateTimeImmutable $syncedUntil,
        public ?\DateTimeImmutable $lastSyncAt,
        public ?\DateTimeImmutable $lastSyncSucceededAt,
        /** Why the last import failed (same codes as lastCheckError); null when it succeeded or none ran. */
        public ?string $lastSyncError,
        public ?string $lastSyncErrorMessage,
        /** Purchase invoices imported by the last import. */
        public int $lastSyncImportedCount,
        /** Invoices the last import skipped (a form other than FA(3)). */
        public int $lastSyncSkippedCount,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(KsefConnectionView $view): self
    {
        return new self(
            $view->id,
            $view->status,
            $view->tokenSet,
            $view->tokenSetAt,
            $view->nip,
            $view->verifiedAt,
            $view->lastCheckedAt,
            $view->lastCheckError,
            $view->lastCheckErrorMessage,
            $view->syncedUntil,
            $view->lastSyncAt,
            $view->lastSyncSucceededAt,
            $view->lastSyncError,
            $view->lastSyncErrorMessage,
            $view->lastSyncImportedCount,
            $view->lastSyncSkippedCount,
            $view->createdAt,
        );
    }
}
