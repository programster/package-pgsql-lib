<?php

/*
 * A library to help with using the pgsql connection object:
 * https://www.php.net/manual/en/book.pgsql.php
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use Programster\PgsqlLib\Exceptions\ExceptionInvalidTableName;
use Programster\PgsqlLib\Exceptions\ExceptionMismatchedRows;
use Programster\PgsqlLib\Exceptions\ExceptionNoData;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;


final class PgsqlLib
{
    // A UUID of any version, in its usual form, for isValidUuid(). D stops $ matching before a trailing newline.
    private const UUID_PATTERN = '#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#iD';


    /**
     * This class only has static methods, so it is never instantiated.
     */
    private function __construct()
    {
    }


    /**
     * Generates column = value pairs, separated by commas, such as for the SET part of an UPDATE query, e.g.
     * "name" = 'bob', "email" = 'bob@example.com'
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
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
    public static function generateQueryPairs(
        \PgSql\Connection $conn,
        array $pairs,
        bool $escapeValues = true,
        bool $escapeIdentifiers = true
    ) : string
    {
        $query = '';

        if (count($pairs) === 0)
        {
            throw new ExceptionNoData("No column/value pairs were provided.");
        }

        if ($escapeValues)
        {
            $pairs = PgsqlLib::escapeValues($conn, $pairs);
        }

        foreach ($pairs as $name => $value)
        {
            if ($escapeIdentifiers)
            {
                $escapedIdentifier = PgsqlLib::escapeIdentifier($conn, (string)$name);
            }
            else
            {
                $escapedIdentifier = (string)$name;
            }

            // Escaped values are never null, as escapeValue() returns the string NULL, but unescaped values can be.
            if ($value === null)
            {
                $query .= "{$escapedIdentifier} = NULL, ";
            }
            else
            {
                $query .= "{$escapedIdentifier} = {$value}, ";
            }
        }

        $query = substr($query, 0, -2); # remove the last comma.
        return $query;
    }


    /**
     * Escape the provided values, using the provided connection.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     *
     * @param array $inputs - the input values to escape.
     *
     * @return array<string|int> - the escaped values, with the same keys as $inputs. See escapeValue() for the form
     * each type of value is returned in.
     *
     * @throws ExceptionUnexpectedValueType - if the inputs contain a type that is not recognized/supported
     */
    public static function escapeValues(\PgSql\Connection $conn, array $inputs) : array
    {
        $escapedValues = [];

        foreach ($inputs as $key => $value)
        {
            $escapedValues[$key] = PgsqlLib::escapeValue($conn, $value);
        }

        return $escapedValues;
    }


    /**
     * Escape an identifier, such as the name of a table, or a column.
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     * @param string $nameOfTableOrColumn
     * @return string - the escaped identifier
     */
    public static function escapeIdentifier(\PgSql\Connection $conn, string $nameOfTableOrColumn) : string
    {
        return pg_escape_identifier($conn, $nameOfTableOrColumn);
    }


    /**
     * Escape a table, qualifying it with its schema if it has one.
     * The schema and table names are escaped separately and joined with a dot, so that PostgreSQL treats them as
     * schema and table, rather than as a single identifier that happens to contain a dot.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     *
     * @param string|Table $table - the table to escape. A string is a table name, without any schema prefix, that
     * PostgreSQL resolves using the connection's search_path. Use a Table to specify the schema.
     *
     * @return string - the escaped table name, e.g. "user", or "app"."user" if the Table has a schema.
     *
     * @throws ExceptionInvalidTableName - if $table is an empty string.
     */
    public static function escapeTableName(\PgSql\Connection $conn, string|Table $table) : string
    {
        $tableObject = is_string($table) ? new Table($table) : $table;
        $escapedTableName = PgsqlLib::escapeIdentifier($conn, $tableObject->getName());

        if ($tableObject->hasSchemaName())
        {
            $escapedSchemaName = PgsqlLib::escapeIdentifier($conn, $tableObject->getSchemaName());
            $escapedTableName = "{$escapedSchemaName}.{$escapedTableName}";
        }

        return $escapedTableName;
    }


    /**
     * Escape the array of string identifiers provided.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     *
     * @param array $namesOfTablesOrColumns - the list of table/column names to escape. Ints are treated as strings,
     * as PHP turns numeric string array keys such as "2024" into ints.
     *
     * @return string[] - the escaped identifiers.
     */
    public static function escapeIdentifiers(\PgSql\Connection $conn, array $namesOfTablesOrColumns) : array
    {
        $escapedIdentifiers = [];

        foreach ($namesOfTablesOrColumns as $identifier)
        {
            $escapedIdentifiers[] = pg_escape_identifier($conn, (string)$identifier);
        }

        return $escapedIdentifiers;
    }


    /**
     * Escape a single value, so that it can be put directly into a query.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     *
     * @param mixed $value - the value to escape. Must be a string, int, float, bool, null or backed enum.
     *
     * @return string|int - the value in the form to put into the query:
     * - a string is returned as a quoted, escaped string literal, e.g. 'bob'
     * - an int is returned unchanged, as an int
     * - a float is returned as a string with full precision, e.g. "0.30000000000000004". PHP's normal float to
     *   string conversion only keeps 14 significant digits, so the float is not returned unchanged. INF, -INF and
     *   NAN are returned as 'Infinity'::float8, '-Infinity'::float8 and 'NaN'::float8, which PostgreSQL accepts
     *   for real, double precision and (from PostgreSQL 14) numeric columns.
     * - a bool is returned as the string TRUE or FALSE
     * - null is returned as the string NULL
     * - a backed enum is escaped as its backing value, e.g. Status::Active with the value 'active' gives 'active'
     *
     * @throws ExceptionUnexpectedValueType - if the type of the value passed in was not recognized
     * so we were unable to handle escaping it.
     */
    public static function escapeValue(\PgSql\Connection $conn, mixed $value) : string|int
    {
        if ($value instanceof \BackedEnum)
        {
            $escapedValue = PgsqlLib::escapeValue($conn, $value->value);
        }
        elseif (is_string($value))
        {
            $escapedValue = pg_escape_literal($conn, $value);
        }
        elseif (is_float($value))
        {
            if (is_nan($value))
            {
                $escapedValue = "'NaN'::float8";
            }
            elseif (is_infinite($value))
            {
                $escapedValue = ($value > 0) ? "'Infinity'::float8" : "'-Infinity'::float8";
            }
            else
            {
                // With serialize_precision set to -1, var_export() gives the shortest string that converts back to
                // exactly the same float, rather than rounding to the 14 significant digits of a string cast. -1 is
                // the default, but set it for this call in case the application has changed it.
                $previousPrecision = ini_set('serialize_precision', '-1');

                try
                {
                    $escapedValue = var_export($value, true);
                }
                finally
                {
                    if ($previousPrecision !== false)
                    {
                        ini_set('serialize_precision', $previousPrecision);
                    }
                }
            }
        }
        elseif (is_int($value))
        {
            $escapedValue = $value;
        }
        elseif (is_bool($value))
        {
            $escapedValue = ($value) ? "TRUE" : "FALSE";
        }
        elseif ($value === null)
        {
            $escapedValue = "NULL";
        }
        else
        {
            $msg =
                "Cannot escape a value of type " . get_debug_type($value) . ". Only strings, ints, floats, bools, " .
                "null and backed enums can be escaped. Convert the value to one of those types first.";

            throw new ExceptionUnexpectedValueType($value, $msg);
        }

        return $escapedValue;
    }


    /**
     * Generate the "where" part of a query based on name/value pairs and the provided conjunction
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
     *
     * @param array $wherePairs - column/value pairs for where clause. Value may or may not be an
     *                            array list of values for WHERE IN(). A null value matches rows where
     *                            the column IS NULL, including when null is one of the values in a list.
     *                            An empty list matches no rows.
     *
     * @param Conjunction $conjunction - the conjunction to use in the where clause.
     *
     * @return string - the where clause of a query such as WHERE "id" = 3
     *
     * @throws ExceptionUnexpectedValueType - if the wherePairs contain a type that is not recognized
     * and thus cannot be escaped.
     */
    public static function generateWhereClause(
        \PgSql\Connection $conn,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        $whereClause = "";
        $whereStrings = [];

        foreach ($wherePairs as $attribute => $searchValue)
        {
            $escapedAttribute = PgsqlLib::escapeIdentifier($conn, (string)$attribute);

            if (is_array($searchValue))
            {
                $isNotNull = function (mixed $value) : bool {
                    return $value !== null;
                };

                $nonNullSearchValues = array_filter($searchValue, $isNotNull);
                $hasNullSearchValue = (count($nonNullSearchValues) !== count($searchValue));

                if (count($searchValue) === 0)
                {
                    $whereString = "FALSE";
                }
                elseif (count($nonNullSearchValues) === 0)
                {
                    // Every value in the list is null.
                    $whereString = "{$escapedAttribute} IS NULL";
                }
                else
                {
                    $escapedSearchValues = PgsqlLib::escapeValues($conn, $nonNullSearchValues);
                    $whereString = "{$escapedAttribute} IN(" . implode(",", $escapedSearchValues)  . ")";

                    if ($hasNullSearchValue)
                    {
                        // IN() never matches NULL, so match it separately. The brackets keep the OR from mixing
                        // with the conjunction between where pairs.
                        $whereString = "({$whereString} OR {$escapedAttribute} IS NULL)";
                    }
                }
            }
            elseif ($searchValue === null)
            {
                $whereString = "{$escapedAttribute} IS NULL";
            }
            else
            {
                $whereString = "{$escapedAttribute} = " . PgsqlLib::escapeValue($conn, $searchValue);
            }

            $whereStrings[] = $whereString;
        }

        if (count($whereStrings) > 0)
        {
            $whereClause = "WHERE " . implode(" {$conjunction->value} ", $whereStrings);
        }

        return $whereClause;
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * load objects that have any/all (depending on $conjunction) of the specified attributes.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
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
    public static function generateSelectWhereQuery(
        \PgSql\Connection $conn,
        string|Table $table,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        $query = "SELECT * FROM " . PgsqlLib::escapeTableName($conn, $table);
        $whereClause = PgsqlLib::generateWhereClause($conn, $wherePairs, $conjunction);

        if ($whereClause !== "")
        {
            $query .= " {$whereClause}";
        }

        return $query;
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * delete objects that have any/all (depending on $conjunction) of the specified attributes.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
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
    public static function generateDeleteWhereQuery(
        \PgSql\Connection $conn,
        string|Table $table,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionNoData(
                "No where pairs were provided, which would delete every row in the table. " .
                "If you really want to empty the table, write that query explicitly."
            );
        }

        $escapedTableName = PgsqlLib::escapeTableName($conn, $table);
        $whereClause = PgsqlLib::generateWhereClause($conn, $wherePairs, $conjunction);
        return "DELETE FROM {$escapedTableName} {$whereClause}";
    }


    /**
     * Creates a batch insert query for inserting lots of data in one go.
     *
     * @param \PgSql\Connection $conn - the PostgreSQL connection
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
     * @throws ExceptionNoData - if no data was passed in the $rows array, for inserting.
     *
     * @throws ExceptionMismatchedRows - if a row is missing a column that another row has, and that column is not in
     * $columnsWithDefaults.
     *
     * @throws ExceptionUnexpectedValueType - if there was an issue performing escaping due to types.
     */
    public static function generateBatchInsertQuery(
        \PgSql\Connection $conn,
        string|Table $table,
        array $rows,
        array $columnsWithDefaults = []
    ) : string
    {
        $valueSets = [];

        if (count($rows) === 0)
        {
            throw new ExceptionNoData("No data to insert.");
        }

        // The columns are every key that appears in any row, sorted so that the order is consistent.
        $columnsLookup = [];

        foreach ($rows as $row)
        {
            foreach ($row as $columnName => $value)
            {
                $columnsLookup[$columnName] = true;
            }
        }

        ksort($columnsLookup);
        $columnNames = array_keys($columnsLookup);
        $escapedColumnNames = PgsqlLib::escapeIdentifiers($conn, $columnNames);

        foreach ($rows as $rowKey => $row)
        {
            $missingColumns = array_diff($columnNames, array_keys($row));
            $missingColumnsWithoutDefaults = array_diff($missingColumns, $columnsWithDefaults);

            if (count($missingColumnsWithoutDefaults) > 0)
            {
                throw new ExceptionMismatchedRows(
                    "Row {$rowKey} is missing the column(s) " . implode(", ", $missingColumnsWithoutDefaults)
                    . ", which other rows provide. Either provide them in every row, or if they have default "
                    . "values in the database, pass them in the columnsWithDefaults parameter."
                );
            }

            $rowValues = [];

            foreach ($columnNames as $columnName)
            {
                if (array_key_exists($columnName, $row))
                {
                    $rowValues[] = (string)PgsqlLib::escapeValue($conn, $row[$columnName]);
                }
                else
                {
                    $rowValues[] = "DEFAULT";
                }
            }

            $valueSets[] = "(" . implode(", ", $rowValues) . ")";
        }

        $escapedTableName = PgsqlLib::escapeTableName($conn, $table);

        $query =
            "INSERT INTO {$escapedTableName}"
            . " (" . implode(",", $escapedColumnNames) . ")"
            . " VALUES " . implode(",", $valueSets);

        return $query;
    }


    /**
     * Generate a UUID of the version given, in lowercase, e.g. "019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37".
     *
     * UuidVersion::V7 is the one to use for primary keys: it starts with the current time, to a 4096th of a
     * millisecond, so UUIDs generated later sort after those generated earlier, as strings or in binary. Within a
     * process, each is always greater than the one before it, even if two are generated within the same 4096th of a
     * millisecond, or the clock goes backwards. UuidVersion::V4 is entirely random, so use it where a UUID mustn't
     * give away when it was made. UuidVersion::V1 is for systems that expect one: it holds the time too, to a 10th
     * of a microsecond, but doesn't sort by it, and ends with a random node, rather than the machine's MAC address.
     *
     * @param UuidVersion $version - the version of UUID to generate.
     *
     * @return string
     */
    public static function generateUuid(UuidVersion $version) : string
    {
        $bytes = match ($version) {
            UuidVersion::V1 => self::generateUuidV1Bytes(),
            UuidVersion::V4 => self::generateUuidV4Bytes(),
            UuidVersion::V7 => self::generateUuidV7Bytes(),
        };

        $hex = bin2hex($bytes);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }


    /**
     * Check whether a string is a UUID of any version, in its usual form: 32 hex digits, in either case, in groups of
     * 8, 4, 4, 4 and 12, separated by hyphens. Nothing else is accepted: no braces, "urn:uuid:" prefix, spaces or
     * trailing newline, as a string that needs tidying first isn't safe to compare with UUIDs as they're stored.
     *
     * @param string $uuid
     *
     * @return bool
     */
    public static function isValidUuid(string $uuid) : bool
    {
        return (preg_match(self::UUID_PATTERN, $uuid) === 1);
    }


    /**
     * Generate the 16 bytes of a UUIDv1: a 60-bit timestamp (100-nanosecond intervals since 1582-10-15, the start of
     * the Gregorian calendar), the version and variant bits, a 14-bit clock sequence, and a 48-bit node.
     *
     * The node is random, with its multicast bit set (RFC 9562 section 6.10), rather than the machine's MAC address,
     * so a UUID doesn't give away which machine made it, and can't clash with one made from a real MAC address. The
     * node and clock sequence are chosen afresh in each process (including after a fork), so processes don't share
     * them. Each timestamp is greater than the last one this process generated, as PHP's clock only gives
     * microseconds.
     *
     * @return string - the bytes, in binary.
     */
    private static function generateUuidV1Bytes() : string
    {
        // The number of 100-nanosecond intervals between 1582-10-15 and 1970-01-01.
        $gregorianOffset = 122192928000000000;

        // The process the node and clock sequence were chosen in, and the timestamp of the last UUIDv1 generated.
        static $s_processId = null;
        static $s_node = '';
        static $s_clockSequence = 0;
        static $s_lastTimestamp = 0;

        if ($s_processId !== getmypid())
        {
            $s_processId = getmypid();
            $s_node = random_bytes(6);
            $s_node[0] = chr(ord($s_node[0]) | 0x01);
            $s_clockSequence = random_int(0, 0x3fff);
            $s_lastTimestamp = 0;
        }

        $time = explode(' ', microtime());
        $microseconds = (int)$time[1] * 1000000 + (int)substr($time[0], 2, 6);
        $timestamp = $microseconds * 10 + $gregorianOffset;

        if ($timestamp <= $s_lastTimestamp)
        {
            $timestamp = $s_lastTimestamp + 1;
        }

        $s_lastTimestamp = $timestamp;
        $timeLow = $timestamp & 0xffffffff;
        $timeMid = ($timestamp >> 32) & 0xffff;
        $versionAndTimeHigh = 0x1000 | (($timestamp >> 48) & 0x0fff);

        // The clock sequence, with the RFC 9562 variant bits set.
        $variantAndClockSequence = 0x8000 | $s_clockSequence;

        return pack('Nnnn', $timeLow, $timeMid, $versionAndTimeHigh, $variantAndClockSequence) . $s_node;
    }


    /**
     * Generate the 16 bytes of a UUIDv4: 122 random bits, with the version and variant bits set.
     * @return string - the bytes, in binary.
     */
    private static function generateUuidV4Bytes() : string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

        // Set the RFC 9562 variant bits.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return $bytes;
    }


    /**
     * Generate the 16 bytes of a UUIDv7: a 60-bit timestamp (milliseconds, then 12 bits of fraction), the version
     * and variant bits, and 62 random bits. Each timestamp is greater than the last one this process generated, even
     * if the clock hasn't moved on, or has gone backwards.
     *
     * @return string - the bytes, in binary.
     */
    private static function generateUuidV7Bytes() : string
    {
        // The timestamp of the last UUIDv7 generated.
        static $s_lastTimestamp = 0;

        $time = explode(' ', microtime());
        $microseconds = (int)$time[1] * 1000000 + (int)substr($time[0], 2, 6);
        $fraction = intdiv(($microseconds % 1000) * 4096, 1000);
        $timestamp = (intdiv($microseconds, 1000) << 12) | $fraction;

        if ($timestamp <= $s_lastTimestamp)
        {
            $timestamp = $s_lastTimestamp + 1;
        }

        $s_lastTimestamp = $timestamp;
        $millisecondsHex = str_pad(dechex($timestamp >> 12), 12, '0', STR_PAD_LEFT);
        $versionAndFractionHex = dechex(0x7000 | ($timestamp & 0xfff));
        $bytes = hex2bin($millisecondsHex . $versionAndFractionHex) . random_bytes(8);

        // Set the RFC 9562 variant bits.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return $bytes;
    }
}
