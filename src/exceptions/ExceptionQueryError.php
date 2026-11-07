<?php

/*
 * An exception to throw if a query fails to execute.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


class ExceptionQueryError extends AbstractException
{
    private readonly string $m_errorMessage;
    private readonly string $m_query;


    /**
     * Create an exception for a query not working.
     * @param string $errorMessage - the error message returned from pg_last_error
     * @param string $query - the query that was executed that had an issue.
     */
    public function __construct(string $errorMessage, string $query)
    {
        $this->m_errorMessage = $errorMessage;
        $this->m_query = $query;
        parent::__construct("There was an issue with your query: " . $errorMessage);
    }


    # Accessors
    public function getQuery() : string { return $this->m_query; }
    public function getErrorMessage() : string { return $this->m_errorMessage; }
}
