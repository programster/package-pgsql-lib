<?php

/*
 * An exception to throw if a query fails to execute.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


class ExceptionQueryError extends AbstractException
{
    private const SERIALIZATION_FAILURE = '40001';
    private const DEADLOCK_DETECTED = '40P01';

    private readonly string $m_errorMessage;
    private readonly string $m_query;
    private readonly string $m_sqlState;


    /**
     * Create an exception for a query not working.
     *
     * @param string $errorMessage - the error message from PostgreSQL.
     *
     * @param string $query - the query that was executed that had an issue.
     *
     * @param string $sqlState - PostgreSQL's five-character error code, e.g. "23505" for a unique violation, or an
     * empty string if there isn't one, such as when the connection was lost.
     */
    public function __construct(string $errorMessage, string $query, string $sqlState = '')
    {
        $this->m_errorMessage = $errorMessage;
        $this->m_query = $query;
        $this->m_sqlState = $sqlState;
        parent::__construct("There was an issue with your query: " . $errorMessage);
    }


    /**
     * Check whether PostgreSQL rejected this because of a clash with another transaction (a serialization failure or a
     * deadlock), so running the whole transaction again may work.
     *
     * @return bool
     */
    public function isRetryable() : bool
    {
        return in_array($this->m_sqlState, [self::SERIALIZATION_FAILURE, self::DEADLOCK_DETECTED], true);
    }


    # Accessors
    public function getQuery() : string { return $this->m_query; }
    public function getErrorMessage() : string { return $this->m_errorMessage; }
    public function getSqlState() : string { return $this->m_sqlState; }
}
