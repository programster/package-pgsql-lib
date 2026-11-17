<?php

/*
 * Tests that PgSqlConnection::query() sends each value as data, never as SQL, converts each type of value
 * correctly, rejects values it can't send, and throws ExceptionQueryError on failure, without a PHP warning first.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;


enum TestParamsTextEnum : string
{
    case A = 'a-value';
}


enum TestParamsIntEnum : int
{
    case B = 2;
}


enum TestParamsPureEnum
{
    case C;
}


class TestQueryParams extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that query() sends values as data, converting each type, and rejects bad ones.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        // Text that would break out of a quoted value, or look like a placeholder, is still just text.
        $trickyText = "it's \$2'; DROP TABLE \"x\"; --";
        $result = $db->query('SELECT $1::text AS "text"', [$trickyText]);
        $this->check(pg_fetch_result($result, 0, 'text') === $trickyText, "Tricky text didn't come back unchanged.");

        $result = $db->query(
            'SELECT $1::int AS "int", $2::bool AS "true", $3::bool AS "false", $4::text IS NULL AS "is_null"',
            [42, true, false, null]
        );

        $row = pg_fetch_assoc($result);
        $this->check($row['int'] === '42', "An int came back as {$row['int']}.");
        $this->check($row['true'] === 't', "true came back as {$row['true']}.");
        $this->check($row['false'] === 'f', "false came back as {$row['false']}.");
        $this->check($row['is_null'] === 't', "null wasn't sent as SQL NULL.");

        // A float keeps its full precision, which (string) would cut to 14 significant digits.
        $result = $db->query('SELECT $1::float8 = 0.30000000000000004::float8 AS "same"', [0.1 + 0.2]);
        $this->check(pg_fetch_result($result, 0, 'same') === 't', "A float lost precision.");

        $specialFloatsQuery = <<<'SQL'
            SELECT $1::float8 = 'Infinity' AS "inf", $2::float8 = '-Infinity' AS "minus_inf",
                $3::float8 = 'NaN' AS "nan"
            SQL;

        $result = $db->query($specialFloatsQuery, [INF, -INF, NAN]);

        $row = pg_fetch_assoc($result);
        $allMatched = ($row['inf'] === 't' && $row['minus_inf'] === 't' && $row['nan'] === 't');
        $this->check($allMatched, "INF, -INF or NAN didn't come back as the same special float.");

        $enums = [TestParamsTextEnum::A, TestParamsIntEnum::B];
        $result = $db->query('SELECT $1::text AS "a", $2::int AS "b"', $enums);
        $row = pg_fetch_assoc($result);
        $this->check($row['a'] === 'a-value' && $row['b'] === '2', "Backed enums weren't sent as their values.");

        // Without params, it's sent as it is, so it can hold several statements, and text like $1 is left alone.
        $result = $db->query("SELECT 1; SELECT '$1' AS \"text\"");
        $this->check(pg_fetch_result($result, 0, 'text') === '$1', "A query without params wasn't sent as it is.");

        $this->checkRejected($db, [new \stdClass()], "an object");
        $this->checkRejected($db, [TestParamsPureEnum::C], "a pure enum");
        $this->checkRejected($db, [[1, 2]], "an array");
        $this->checkRejected($db, ['a' => 1], "params keyed by name");
        $this->checkQueryErrorWithoutWarning($db);
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function check(bool $condition, string $message) : void
    {
        if ($condition === false)
        {
            $this->m_errorMessages[] = $message;
        }
    }


    /**
     * Check that the params are rejected with ExceptionUnexpectedValueType, before anything is sent.
     * @param \Programster\PgsqlLib\PgSqlConnection $db
     * @param array<mixed> $params
     * @param string $description - what's wrong with them, for the failure message.
     */
    private function checkRejected(\Programster\PgsqlLib\PgSqlConnection $db, array $params, string $description) : void
    {
        try
        {
            $db->query('SELECT $1::text', $params);
            $this->m_errorMessages[] = "Params with {$description} weren't rejected.";
        }
        catch (ExceptionUnexpectedValueType)
        {
            // Expected.
        }
    }


    private function checkQueryErrorWithoutWarning(\Programster\PgsqlLib\PgSqlConnection $db) : void
    {
        $failingQuery = 'SELECT * FROM "table_that_does_not_exist" WHERE "id" = $1';

        $throwOnEveryWarning = function (int $severity, string $message) : never {
            throw new \ErrorException($message, 0, $severity);
        };

        set_error_handler($throwOnEveryWarning);

        try
        {
            $db->query($failingQuery, [1]);
            $this->m_errorMessages[] = "A failing query did not throw.";
        }
        catch (ExceptionQueryError $ex)
        {
            $this->check($ex->getQuery() === $failingQuery, "The exception had the wrong query: {$ex->getQuery()}");
        }
        catch (\ErrorException $ex)
        {
            $this->m_errorMessages[] = "A PHP warning was raised before ExceptionQueryError: {$ex->getMessage()}";
        }
        finally
        {
            restore_error_handler();
        }
    }
}
