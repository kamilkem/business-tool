<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus\Job;

use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Job\OrganizationJobInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Runs an OrganizationJobInterface job inside its organization (ADR 0025 §4): when the worker handles
 * it, switches the tenant to the job's organization before the handler and resets it afterwards, also
 * when the handler throws. An organization that no longer exists makes the job unrecoverable (straight
 * to `failed`, no retries). Dispatching (no ReceivedStamp) and other jobs pass through untouched.
 */
final readonly class SwitchTenantMiddleware implements MiddlewareInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $job = $envelope->getMessage();
        if (!$job instanceof OrganizationJobInterface || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $organizationId = $job->organizationId();
        $organization = Uuid::isValid($organizationId) ? $this->organizations->findById(Uuid::fromString($organizationId)) : null;
        if (null === $organization) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The organization "%s" of the job %s does not exist.', $organizationId, $job::class));
        }

        $this->tenantContext->switchTo($organization);
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->tenantContext->reset();
        }
    }
}
