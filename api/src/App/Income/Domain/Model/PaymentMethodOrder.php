<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodOrderMismatchException;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Uid\Uuid;

/**
 * Reorders an organization's active payment methods (ADR 0030 §3). The order belongs to the list,
 * not to one method, so this one rule changes several PaymentMethod rows at once; the repository
 * saves them in one flush. Deactivated methods keep their position: they are listed after the
 * active ones anyway, and a reactivated one goes to the end.
 */
final class PaymentMethodOrder
{
    /**
     * @param list<PaymentMethod> $activeMethods all the organization's active methods
     * @param list<string>        $orderedIds    the ids of exactly those methods, each once, in the new order
     *
     * @throws IncomeManagementForbiddenException  when the actor is not an owner or manager
     * @throws PaymentMethodOrderMismatchException when the ids are not exactly the active methods
     *                                             (one was added, deactivated or reactivated meanwhile)
     */
    public static function apply(array $activeMethods, array $orderedIds, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);

        $byId = [];
        foreach ($activeMethods as $method) {
            if ($method->isActive()) {
                $byId[$method->getId()->toRfc4122()] = $method;
            }
        }

        $ids = array_map(static fn (string $id): string => strtolower($id), $orderedIds);
        $sorted = $ids;
        sort($sorted);
        $expected = array_keys($byId);
        sort($expected);
        if ($sorted !== $expected) {
            throw PaymentMethodOrderMismatchException::of(\count($expected), \count($ids));
        }

        foreach ($ids as $position => $id) {
            $byId[$id]->moveTo($position);
        }
    }
}
