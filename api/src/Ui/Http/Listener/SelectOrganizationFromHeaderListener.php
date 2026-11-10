<?php

declare(strict_types=1);

namespace Ui\Http\Listener;

use App\Organization\Domain\Repository\MembershipRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\User\Domain\Model\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Selects the organization from the X-Organization-Id header, after checking that the signed-in
 * user is a member of it. Runs after the firewall (priority 8) and before API Platform reads data.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class SelectOrganizationFromHeaderListener
{
    public const string HEADER = 'X-Organization-Id';

    public function __construct(
        private Security $security,
        private MembershipRepositoryInterface $memberships,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $organizationId = $event->getRequest()->headers->get(self::HEADER);
        if (null === $organizationId || '' === $organizationId) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // access_control rejects unauthenticated requests.
        }

        if (!Uuid::isValid($organizationId)) {
            throw new BadRequestHttpException(\sprintf('Invalid %s header.', self::HEADER));
        }

        $membership = $this->memberships->findOne($user->getId(), Uuid::fromString($organizationId));
        if (null === $membership) {
            throw new AccessDeniedHttpException('You are not a member of this organization.');
        }

        $this->tenantContext->switchTo($membership->getOrganization());
    }
}
