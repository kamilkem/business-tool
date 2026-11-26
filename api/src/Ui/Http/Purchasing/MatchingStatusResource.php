<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingStatusView;
use Ui\Http\Purchasing\Processor\RequestMatchingProcessor;
use Ui\Http\Purchasing\Provider\MatchingStatusProvider;

/**
 * Where matching purchased items to products stands in the organization selected by the X-Organization-Id
 * header, and "Match again" (ADR 0046). Owners and managers only: anyone else gets a 403.
 */
#[ApiResource(
    shortName: 'MatchingStatus',
    operations: [
        new Get(
            uriTemplate: '/purchased-items/match',
            openapi: new Operation(
                summary: 'How many items wait for the model, whether it is on, and what its last run did.',
                description: 'Owners and managers only (403 otherwise). `waitingCount` is the number of items with no product that matching may change (not confirmed, not taken off their product by a person): what "Match again" goes through. `modelEnabled` is false while the server has no model configured: only the owner\'s remembered aliases match items then. `lastRun` is the newest run that used the model, or null when none did.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: MatchingStatusProvider::class,
        ),
        new Post(
            uriTemplate: '/purchased-items/match',
            status: 202,
            openapi: new Operation(
                summary: 'Puts the items with no product to the model again ("Match again").',
                description: 'Owners and managers only (403 otherwise). Queues a run over every item with no product that the model has not been through since this request, and returns at once with the status; the run shows up in `lastRun` when it starts and ends. If one is already queued or running, no second one is queued. Items a person confirmed or took off their product are never changed. The request has no body. With no model configured the run can only apply the owner\'s remembered aliases, and is not recorded.',
                responses: [
                    '202' => new Response('The run is queued; the status as it is now.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            input: false,
            read: false,
            processor: RequestMatchingProcessor::class,
        ),
    ],
)]
final readonly class MatchingStatusResource
{
    public function __construct(
        public int $waitingCount,
        public bool $modelEnabled,
        public ?MatchingStatusRun $lastRun,
    ) {
    }

    public static function fromView(MatchingStatusView $view): self
    {
        return new self($view->waitingCount, $view->modelEnabled, null === $view->lastRun ? null : MatchingStatusRun::fromView($view->lastRun));
    }
}
