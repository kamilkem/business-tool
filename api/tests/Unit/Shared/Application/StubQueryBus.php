<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryInterface;

/** Fake bus: answers queries of another module with a callback, and keeps them for assertions. */
final class StubQueryBus implements QueryBusInterface
{
    /** @var list<QueryInterface> */
    public array $queries = [];

    /** @param \Closure(QueryInterface): mixed $answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    public function ask(QueryInterface $query): mixed
    {
        $this->queries[] = $query;

        return ($this->answer)($query);
    }
}
