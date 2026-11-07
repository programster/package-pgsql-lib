<?php

/*
 * Tests that PgSqlConnection::query() returns a Result on success, and throws ExceptionQueryError on failure, without
 * also raising a PHP warning first.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;


class TestQueryErrors extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that query() returns a Result, or throws ExceptionQueryError on failure.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        $result = $db->query('SELECT 1 AS "one"');

        if (pg_fetch_result($result, 0, 'one') !== '1')
        {
            $this->m_errorMessages[] = "A successful query did not return the expected result.";
        }

        # Convert every warning to an exception, as many frameworks do, to check that pg_query's warning doesn't
        # reach the application's error handler before our own exception is thrown.
        $failingQuery = 'SELECT * FROM "table_that_does_not_exist"';

        $throwOnEveryWarning = function (int $severity, string $message) : never {
            throw new \ErrorException($message, 0, $severity);
        };

        set_error_handler($throwOnEveryWarning);

        try
        {
            $db->query($failingQuery);
            $this->m_errorMessages[] = "A failing query did not throw.";
        }
        catch (ExceptionQueryError $ex)
        {
            if ($ex->getQuery() !== $failingQuery)
            {
                $this->m_errorMessages[] = "The exception had the wrong query: {$ex->getQuery()}";
            }

            if (str_contains($ex->getErrorMessage(), 'table_that_does_not_exist') === false)
            {
                $this->m_errorMessages[] = "The exception's error message didn't come from PostgreSQL: {$ex->getErrorMessage()}";
            }
        }
        catch (\ErrorException $ex)
        {
            $this->m_errorMessages[] = "A PHP warning was raised before ExceptionQueryError: {$ex->getMessage()}";
        }
        finally
        {
            restore_error_handler();
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }
}
