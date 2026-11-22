<?php

/*
 * An ordered collection of queries for a Transaction. Its constructor takes Query objects, as a collection's elements
 * must be declared as one type, but add() and $collection[] = $query also take SQL strings, and make a Query from them.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use Programster\Collections\AbstractCollection;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;

/**
 * @extends AbstractCollection<Query>
 */
final class QueryCollection extends AbstractCollection
{
    public function __construct(Query ...$queries)
    {
        parent::__construct(...$queries);
    }


    /**
     * Set the query with a key, or add one to the end if the key is null. Every way of adding a query comes through
     * here, including add() and $collection[] = $query.
     *
     * @param mixed $offset - the key, or null to add the query to the end.
     *
     * @param Query|string $value - the query, or its SQL.
     *
     * @return void
     *
     * @throws ExceptionInvalidQuery - if a string can't be used as a query (see Query).
     */
    public function offsetSet(mixed $offset, mixed $value) : void
    {
        parent::offsetSet($offset, is_string($value) ? new Query($value) : $value);
    }
}
