<?php

/*
 * A class to wrap around a PgSql\Connection, providing convenience methods for escaping, generating and executing
 * queries on that connection.
 * https://www.php.net/manual/en/class.pgsql-connection.php
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;


use PgSql\Result;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTableName;
use Programster\PgsqlLib\Exceptions\ExceptionMismatchedRows;
use Programster\PgsqlLib\Exceptions\ExceptionNoData;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;


final readonly class PgSqlConnection
{
    private \PgSql\Connection $m_resource; # the underlying pgsql connection resource.


    /**
     * Create a PostgreSQL connection wrapper.
     *
     * @param \PgSql\Connection $pgsqlResource - the connection to wrap. This may be an asynchronous connection that
     * is still being established, in which case poll it with pg_connect_poll() until it returns PGSQL_POLLING_OK
     * before running any queries.
     *
     * @throws Exceptions\ExceptionConnectionError - if the connection has failed.
     */
    public function __construct(\PgSql\Connection $pgsqlResource)
    {
        if (pg_connection_status($pgsqlResource) === PGSQL_CONNECTION_BAD)
        {
            throw new Exceptions\ExceptionConnectionError(
                "The connection provided is not connected to the PostgreSQL database: " . pg_last_error($pgsqlResource)
            );
        }

        $this->m_resource = $pgsqlResource;
    }


    /**
     * Create a new PostgreSQL database connection
     *
     * @param string $host - the IP or FQDN of where the postgreSQL database is hosted.
     *
     * @param string $dbName - the name of the database.
     *
     * @param string $user - the user to connect with
     *
     * @param string $password - the password for that user.
     *
     * @param bool $forceNew - true to always open a new, independent connection. false lets PHP's pg_connect() return
     * a connection that this script already has open with the same details, in which case both PgSqlConnection
     * objects share one database session: its transactions, session settings (such as search_path) and temporary
     * tables, and closing one closes the other. Use true unless you specifically want that sharing, and to share a
     * connection deliberately, pass the same PgSqlConnection object around instead.
     *
     * @param int $port - (optional) the port to connect on. Defaults to PostgreSQL's default port of 5432.
     *
     * @param bool $useUtf8 - (optional) whether to use UTF8 encoding. Defaults to true.
     *
     * @param bool $useAsync - Whether to set PGSQL_CONNECT_ASYNC. If set then the connection is established
     * asynchronously, so poll it with pg_connect_poll($connection->getResource()) until it returns PGSQL_POLLING_OK
     * before running any queries.
     *
     * @return PgSqlConnection - the connection to the PostgreSQL database
     *
     * @throws Exceptions\ExceptionConnectionError - if there was an issue connecting to the database. The message
     * includes the reason given by PostgreSQL.
     */
    public static function create(
        string $host,
        string $dbName,
        string $user,
        string $password,
        bool $forceNew,
        int $port = 5432,
        bool $useUtf8 = true,
        bool $useAsync = false
    ) : PgSqlConnection
    {
        $connString =
            "host=" . PgSqlConnection::quoteConnectionStringValue($host)
            . " dbname=" . PgSqlConnection::quoteConnectionStringValue($dbName)
            . " user=" . PgSqlConnection::quoteConnectionStringValue($user)
            . " password=" . PgSqlConnection::quoteConnectionStringValue($password)
            . " port=" . $port;

        if ($useUtf8)
        {
            $connString .= " options='--client_encoding=UTF8'";
        }

        $connectionOptions = 0;

        if ($forceNew)
        {
            $connectionOptions = $connectionOptions | PGSQL_CONNECT_FORCE_NEW;
        }

        if ($useAsync)
        {
            $connectionOptions = $connectionOptions | PGSQL_CONNECT_ASYNC;
        }

        $connect = function () use ($connString, $connectionOptions) : \PgSql\Connection|false {
            return pg_connect($connString, $connectionOptions);
        };

        $warning = PgSqlConnection::callCapturingWarning($connect, $connection);

        if ($connection === false)
        {
            $reason = preg_replace('/^pg_connect\(\): /', '', $warning);
            throw new Exceptions\ExceptionConnectionError("Failed to connect to the PostgreSQL database: {$reason}");
        }

        return new PgSqlConnection($connection);
    }


    /**
     * Generates column = value pairs, separated by commas, such as for the SET part of an UPDATE query, e.g.
     * "name" = 'bob', "email" = 'bob@example.com'
     * An alias for PgsqlLib::generateQueryPairs
     *
     * @param array $pairs - column-name/value pairs. Must not be empty.
     *
     * @param bool $escapeValues - (optional) set to false to disable escaping of values if you have already taken
     * care of this. A null value still becomes NULL.
     *
     * @param bool $escapeIdentifiers - (optional) set to false to disable escaping of identifiers if you have already
     * taken care of this.
     *
     * @return string - the generated pairs, which can be appended to a query.
     *
     * @throws ExceptionNoData - if $pairs is empty, as that would generate invalid SQL.
     * @throws ExceptionUnexpectedValueType - if a value is of a type that cannot be escaped.
     */
    public function generateQueryPairs(array $pairs, bool $escapeValues = true, bool $escapeIdentifiers = true) : string
    {
        return PgsqlLib::generateQueryPairs($this->getResource(), $pairs, $escapeValues, $escapeIdentifiers);
    }


    /**
     * Executes a pg_query call on the underlying connection resource.
     *
     * @param string $query - the query to execute.
     *
     * @return Result - the result of the query.
     *
     * @throws ExceptionQueryError - if there was an issue executing the query. The exception holds PostgreSQL's
     * error message (getErrorMessage()) and the query that failed (getQuery()).
     */
    public function query(string $query) : Result
    {
        $runQuery = function () use ($query) : Result|false {
            return pg_query($this->getResource(), $query);
        };

        PgSqlConnection::callCapturingWarning($runQuery, $result);

        if ($result === false)
        {
            throw new ExceptionQueryError(pg_last_error($this->getResource()), $query);
        }

        return $result;
    }


    /**
     * Escape an identifier, such as the name of a table, or a column.
     * An alias for PgsqlLib::escapeIdentifier
     *
     * @param string $nameOfTableOrColumn - the identifier to escape.
     *
     * @return string - the escaped identifier, e.g. "user"
     */
    public function escapeIdentifier(string $nameOfTableOrColumn) : string
    {
        return PgsqlLib::escapeIdentifier($this->getResource(), $nameOfTableOrColumn);
    }


    /**
     * Escape a table, qualifying it with its schema if it has one.
     * An alias for PgsqlLib::escapeTableName
     *
     * @param string|Table $table - the table to escape. A string is a table name, without any schema prefix, that
     * PostgreSQL resolves using the connection's search_path. Use a Table to specify the schema.
     *
     * @return string - the escaped table name, e.g. "user", or "app"."user" if the Table has a schema.
     *
     * @throws ExceptionInvalidTableName - if $table is an empty string.
     */
    public function escapeTableName(string|Table $table) : string
    {
        return PgsqlLib::escapeTableName($this->getResource(), $table);
    }


    /**
     * Escape the array of identifiers provided.
     * An alias for PgsqlLib::escapeIdentifiers
     *
     * @param array $identifiers - the list of table/column names to escape. Ints are treated as strings, as PHP turns
     * numeric string array keys such as "2024" into ints.
     *
     * @return string[] - the escaped identifiers.
     */
    public function escapeIdentifiers(array $identifiers) : array
    {
        return PgsqlLib::escapeIdentifiers($this->getResource(), $identifiers);
    }


    /**
     * Escape the provided values.
     * An alias for PgsqlLib::escapeValues
     *
     * @param array $inputs - the input values to escape.
     *
     * @return array<string|int> - the escaped values, with the same keys as $inputs. See escapeValue() for the form
     * each type of value is returned in.
     *
     * @throws ExceptionUnexpectedValueType - if the inputs contain a type that is not recognized/supported
     */
    public function escapeValues(array $inputs) : array
    {
        return PgsqlLib::escapeValues($this->getResource(), $inputs);
    }


    /**
     * Escape a single value, so that it can be put directly into a query.
     * An alias for PgsqlLib::escapeValue
     *
     * @param mixed $input - the value to escape. Must be a string, int, float, bool, null or backed enum.
     *
     * @return string|int - the value in the form to put into the query:
     * - a string is returned as a quoted, escaped string literal, e.g. 'bob'
     * - an int is returned unchanged, as an int
     * - a float is returned as a string with full precision, e.g. "0.30000000000000004". INF, -INF and NAN are
     *   returned as 'Infinity'::float8, '-Infinity'::float8 and 'NaN'::float8.
     * - a bool is returned as the string TRUE or FALSE
     * - null is returned as the string NULL
     * - a backed enum is escaped as its backing value
     *
     * @throws Exceptions\ExceptionUnexpectedValueType - if the input variable is a type the package
     * does not recognize, and thus cannot handle.
     */
    public function escapeValue(mixed $input) : string|int
    {
        return PgsqlLib::escapeValue($this->getResource(), $input);
    }


    /**
     * Creates a batch insert query for inserting lots of data in one go.
     *
     * @param string|Table $table - the table to insert into. A string is a table name that PostgreSQL resolves using
     * the connection's search_path. Use a Table to specify the schema.
     *
     * @param array $rows - the rows of data to insert in name/value pairs. The keys don't need to be in the same order.
     * The query inserts into every column that appears in any of the rows. If a row is missing one of those columns,
     * the column must be listed in $columnsWithDefaults, otherwise an ExceptionMismatchedRows is thrown.
     *
     * @param string[] $columnsWithDefaults - (optional) the names of columns that rows may leave out, in order to have
     * PostgreSQL fill them in with the column's default value. A row that leaves one of these out gets DEFAULT for it.
     * A row that explicitly provides null for a column still gets NULL, not the default.
     *
     * @return string - the query to execute to batch insert the data.
     *
     * @throws ExceptionUnexpectedValueType - if there was an issue performing the escaping.
     * @throws ExceptionNoData - if no data was provided for insertion.
     *
     * @throws ExceptionMismatchedRows - if a row is missing a column that another row has, and that column is not in
     * $columnsWithDefaults.
     */
    public function generateBatchInsertQuery(
        string|Table $table,
        array $rows,
        array $columnsWithDefaults = []
    ) : string
    {
        return PgsqlLib::generateBatchInsertQuery(
            $this->getResource(),
            $table,
            $rows,
            $columnsWithDefaults
        );
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * load objects that have any/all (depending on $conjunction) of the specified attributes.
     *
     * @param string|Table $table - the table to select from. A string is a table name that PostgreSQL resolves using
     * the connection's search_path. Use a Table to specify the schema.
     *
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be loaded. If empty, every row is selected.
     *
     * @param Conjunction $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                                   any of the specified attributes in order to be loaded.
     *
     * @return string - the raw sql string to send to the database.
     *
     * @throws ExceptionUnexpectedValueType - if there was an issue performing escaping due to types.
     */
    public function generateSelectWhereQuery(
        string|Table $table,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        return PgsqlLib::generateSelectWhereQuery(
            $this->getResource(),
            $table,
            $wherePairs,
            $conjunction
        );
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * delete objects that have any/all (depending on $conjunction) of the specified attributes.
     * An alias for PgsqlLib::generateDeleteWhereQuery
     *
     * @param string|Table $table - the table to delete from. A string is a table name that PostgreSQL resolves using
     * the connection's search_path. Use a Table to specify the schema.
     *
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be deleted. Must not be empty, as that would delete every row in the table.
     *                           A pair with an empty array as its value is allowed, and matches no rows.
     *
     * @param Conjunction $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                                   any of the specified attributes in order to be deleted.
     *
     * @return string - the raw sql string to send to the database.
     *
     * @throws ExceptionNoData - if $wherePairs is empty. To delete every row, write that query explicitly instead.
     * @throws ExceptionUnexpectedValueType - if there was an issue performing escaping due to types.
     */
    public function generateDeleteWhereQuery(
        string|Table $table,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        return PgsqlLib::generateDeleteWhereQuery(
            $this->getResource(),
            $table,
            $wherePairs,
            $conjunction
        );
    }


    /**
     * Call a pgsql function, capturing any warning it raises instead of letting it reach the application's error
     * handler. The pgsql functions raise a warning as well as returning false on failure, and we report the failure
     * through an exception instead. Otherwise an error handler that converts warnings to exceptions, as many
     * frameworks do, would throw before we could throw our own exception. Unlike the @ operator, this works whatever
     * the application's error handler does.
     *
     * @param callable $function - the function to call.
     *
     * @param mixed $result - set to whatever the function returned.
     *
     * @return string - the message of the last warning the function raised, or an empty string if there was none.
     */
    private static function callCapturingWarning(callable $function, mixed &$result) : string
    {
        $warning = "";

        $recordWarning = function (int $severity, string $message) use (&$warning) : bool {
            $warning = $message;
            return true;
        };

        set_error_handler($recordWarning);

        try
        {
            $result = $function();
        }
        finally
        {
            restore_error_handler();
        }

        return $warning;
    }


    /**
     * Quote a value for a libpq connection string, so that values containing spaces, quotes or backslashes work,
     * and can't add other options to the connection string.
     * https://www.postgresql.org/docs/current/libpq-connect.html#LIBPQ-CONNSTRING-KEYWORD-VALUE
     *
     * @param string $value - the value to quote.
     *
     * @return string - the value wrapped in single quotes, with any backslashes and single quotes escaped.
     */
    private static function quoteConnectionStringValue(string $value) : string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }


    # Accessors
    public function getResource() : \PgSql\Connection { return $this->m_resource; }
}

