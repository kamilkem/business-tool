<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\ListKsefConnectedOrganizations;

use App\Shared\Application\Query\QueryInterface;

/**
 * For operator tasks run outside any organization (`app:ksef:read-invoice-items`): the ids of the
 * organizations that have a KSeF connection, whatever its status (a rejected or removed token
 * included, since such a task may only use what is stored). The answer is a list<string>, by id; only
 * ids cross organizations, no tenant data.
 */
final readonly class ListKsefConnectedOrganizationsQuery implements QueryInterface
{
}
