<?php

/*
 * One SQL statement that's safe to run in a Transaction, with any values for its $1, $2 etc. placeholders. Values are
 * sent separately from the SQL, so they need no escaping, and a large one isn't copied into the query. It's checked as
 * it's created, so a mistake is reported on the line that made it.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;
use Programster\PgsqlLib\Internal\SqlStatementValidator;


final readonly class Query
{
    private string $m_sql;

    /** @var list<string|null> */
    private array $m_params;


    /**
     * Create a query.
     *
     * @param string $sql - exactly one SQL statement. A trailing semicolon is fine.
     *
     * @param array<mixed> $params - a list of strings, ints, floats, bools, nulls or backed enums, for its $1, $2 etc.
     * placeholders, in order.
     *
     * @throws ExceptionInvalidQuery - if it's empty, holds more than one statement, has an unterminated string or
     * comment, controls the transaction itself (e.g. COMMIT), can't run in a transaction (e.g. VACUUM), needs a COPY
     * data stream, or its placeholders don't match $params. Only PostgreSQL can tell whether SQL is entirely valid, so
     * anything else wrong with it is reported when it runs.
     *
     * @throws ExceptionUnexpectedValueType - if $params isn't a list, or holds an unsupported type of value.
     */
    public function __construct(string $sql, array $params = [])
    {
        if (array_is_list($params) === false)
        {
            throw new ExceptionUnexpectedValueType($params, "The params must be a list, in the order of \$1, \$2 etc.");
        }

        $converted = [];

        foreach ($params as $param)
        {
            $converted[] = PgsqlLib::convertQueryParam($param);
        }

        SqlStatementValidator::assertValid($sql, count($converted));
        $this->m_sql = $sql;
        $this->m_params = $converted;
    }


    public function hasParams() : bool
    {
        return count($this->m_params) > 0;
    }


    # Accessors
    public function getSql() : string { return $this->m_sql; }

    /** @return list<string|null> - the values, as the text that's sent for them. */
    public function getParams() : array { return $this->m_params; }
}
