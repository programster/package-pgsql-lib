<?php

/*
 * The results of a transaction's queries, in the same order as the queries.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use PgSql\Result;
use Programster\Collections\AbstractReadonlyCollection;

/**
 * @extends AbstractReadonlyCollection<Result>
 */
final readonly class QueryResultCollection extends AbstractReadonlyCollection
{
    public function __construct(Result ...$results)
    {
        parent::__construct(...$results);
    }
}
