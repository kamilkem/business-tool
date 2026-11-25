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
use Ui\Http\Ksef\Processor\ChangeKsefSyncStartDateProcessor;
use Ui\Http\Ksef\Processor\RemoveKsefTokenProcessor;
use Ui\Http\Ksef\Processor\RequestKsefImportProcessor;
use Ui\Http\Ksef\Processor\ResetKsefSyncStartDateProcessor;
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
        new Post(
            uriTemplate: '/ksef-connection/import',
            status: 202,
            openapi: new Operation(
                summary: 'Queues an import of purchase invoices from KSeF ("Import now").',
                description: 'Owners and managers only (403 otherwise). Queues the organization\'s import on the jobs worker and returns at once; the outcome shows later in the sync status (lastSyncAt, lastSyncError, the counts). If an import is already queued or running, no second one is queued. At most once every 15 minutes, and not before syncRetryNotBefore after KSeF\'s rate limit (429). The request has no body. 404 when no token was ever saved, 409 when the token was removed or KSeF rejected it, 422 when KSeF is not available to the organization.',
                responses: [
                    '202' => new Response('The import is queued; the connection with lastImportRequestedAt.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The organization has no KSeF connection yet.'),
                    '409' => new Response('The KSeF token was removed, or KSeF rejected it: the owner saves a new one.'),
                    '422' => new Response('KSeF is not available to the organization (country other than PL, or no valid NIP).'),
                    '429' => new Response('An import was requested less than 15 minutes ago, or KSeF\'s rate limit is still running.'),
                ],
            ),
            input: false,
            read: false,
            processor: RequestKsefImportProcessor::class,
        ),
        new Post(
            uriTemplate: '/ksef-connection/sync-start-date',
            status: 200,
            openapi: new Operation(
                summary: 'Chooses the date of issue from which purchase invoices are imported from KSeF.',
                description: 'Owners and managers only (403 otherwise). Invoices issued on or after syncStartDate (YYYY-MM-DD, in the organization\'s time zone) are imported; DELETE goes back to the default (defaultSyncStartDate). The date may not be after today or before 2026-02-01 (422). A date earlier than the current start makes the next import read KSeF again from it, passing over invoices already imported; a later one only affects imports not done yet. Imported invoices are never removed. 404 when no token was ever saved.',
                responses: [
                    '200' => new Response('The connection with the new start.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The organization has no KSeF connection yet.'),
                    '422' => new Response('The date is missing, not a YYYY-MM-DD date, after today or before 2026-02-01 (code ksef.invalid_sync_start_date.*).'),
                ],
            ),
            input: ChangeKsefSyncStartDateInput::class,
            read: false,
            processor: ChangeKsefSyncStartDateProcessor::class,
        ),
        new Delete(
            uriTemplate: '/ksef-connection/sync-start-date',
            openapi: new Operation(
                summary: 'Goes back to the default start of the KSeF import.',
                description: 'Owners and managers only (403 otherwise). Invoices are imported from defaultSyncStartDate again: the first day of the month before the connection was created. As with POST, an earlier start makes the next import read KSeF again from it; imported invoices are never removed. 404 when no token was ever saved.',
                responses: [
                    '204' => new Response('The import starts at the default date.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The organization has no KSeF connection yet.'),
                ],
            ),
            read: false,
            processor: ResetKsefSyncStartDateProcessor::class,
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
        /** The date of issue (`YYYY-MM-DD`) from which invoices are imported, as chosen by an owner or manager; null for the default. */
        public ?string $syncStartDate,
        /** The start of the import while none is chosen (`YYYY-MM-DD`): the first day of the month before the connection was created. */
        public string $defaultSyncStartDate,
        /** Invoices stored in KSeF before this instant are imported; null before the first import. */
        public ?\DateTimeImmutable $syncedUntil,
        public ?\DateTimeImmutable $lastSyncAt,
        public ?\DateTimeImmutable $lastSyncSucceededAt,
        /**
         * Why the last import failed (same codes as lastCheckError), or `invoice_unreadable` when it
         * completed but skipped invoices it could not read (named in lastSyncErrorMessage); null when it
         * succeeded or none ran.
         */
        public ?string $lastSyncError,
        public ?string $lastSyncErrorMessage,
        /** Purchase invoices imported by the last import. */
        public int $lastSyncImportedCount,
        /** Invoices the last import skipped: a form other than FA(3), or unreadable. */
        public int $lastSyncSkippedCount,
        /** After KSeF's rate limit (429): no import runs before this instant; null otherwise. */
        public ?\DateTimeImmutable $syncRetryNotBefore,
        /** When "Import now" was last requested; another request is accepted 15 minutes later. */
        public ?\DateTimeImmutable $lastImportRequestedAt,
        /** How the last import fetched the invoices: `single` (one at a time) or `export` (a package, when more than 64 were waiting); null before the first. */
        public ?string $lastImportMethod,
        /** Invoices the last import knew were still waiting; null when it could not tell (none ran, or KSeF is preparing a package). */
        public ?int $importBacklogCount,
        /** Whether more than importBacklogCount may be waiting: the last import did not reach the end. */
        public bool $importBacklogIsMinimum,
        /** KSeF is preparing a package of invoices; the next import picks it up. */
        public bool $exportPending,
        /** The start of the scheduled import expected to finish the backlog; null when nothing is known to be waiting. */
        public ?\DateTimeImmutable $importEstimatedAt,
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
            $view->syncStartDate,
            $view->defaultSyncStartDate,
            $view->syncedUntil,
            $view->lastSyncAt,
            $view->lastSyncSucceededAt,
            $view->lastSyncError,
            $view->lastSyncErrorMessage,
            $view->lastSyncImportedCount,
            $view->lastSyncSkippedCount,
            $view->syncRetryNotBefore,
            $view->lastImportRequestedAt,
            $view->lastImportMethod,
            $view->importBacklogCount,
            $view->importBacklogIsMinimum,
            $view->exportPending,
            $view->importEstimatedAt,
            $view->createdAt,
        );
    }
}
