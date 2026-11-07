<?php

/*
 * Tests PgSqlConnection::create():
 * - forceNew gives separate database sessions, and without it the existing connection is reused,
 * - asynchronous connections can be created and used once polled,
 * - a failed connection throws ExceptionConnectionError with PostgreSQL's reason, without raising a PHP warning,
 * - values with spaces, quotes and backslashes are quoted in the connection string.
 *
 * This creates and drops a database role, so the test user needs the CREATEROLE privilege.
 */

use Programster\PgsqlLib\Exceptions\ExceptionConnectionError;
use Programster\PgsqlLib\PgSqlConnection;


class TestConnection extends AbstractTest
{
    private const ODD_USER = 'pgsql lib "test" user';
    private const ODD_PASSWORD = "correct horse's \\ battery staple";


    public function getDescription() : string
    {
        return "Test that create() handles async connections, failures and values that need quoting.";
    }


    public function run() : void
    {
        $this->checkForceNew();
        $this->checkAsyncConnection();
        $this->checkFailureReason();
        $this->checkQuotedValues();
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    /**
     * With forceNew, each connection is a separate database session. Without it, PHP returns the connection that's
     * already open with the same details, so both objects share one session.
     */
    private function checkForceNew() : void
    {
        $getBackendPid = function (PgSqlConnection $db) : string {
            return pg_fetch_result($db->query('SELECT pg_backend_pid()'), 0, 0);
        };

        $first = PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, forceNew: true, port: $this->getPort());
        $second = PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, forceNew: true, port: $this->getPort());

        if ($getBackendPid($first) === $getBackendPid($second))
        {
            $this->m_errorMessages[] = "Two connections with forceNew set to true shared a database session.";
        }

        $reused = PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, forceNew: false, port: $this->getPort());

        if ($getBackendPid($reused) !== $getBackendPid($second))
        {
            $this->m_errorMessages[] = "A connection with forceNew set to false didn't reuse the existing connection.";
        }

        pg_close($first->getResource());
        pg_close($second->getResource());
    }


    private function checkAsyncConnection() : void
    {
        $db = PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, forceNew: true, port: $this->getPort(), useAsync: true);
        $status = PGSQL_POLLING_ACTIVE;
        $attempts = 0;

        while ($status !== PGSQL_POLLING_OK && $status !== PGSQL_POLLING_FAILED && $attempts < 500)
        {
            usleep(10000);
            $status = pg_connect_poll($db->getResource());
            $attempts++;
        }

        if ($status !== PGSQL_POLLING_OK)
        {
            $this->m_errorMessages[] = "The async connection did not finish connecting.";
        }
        elseif (pg_fetch_result($db->query('SELECT 1'), 0, 0) !== '1')
        {
            $this->m_errorMessages[] = "A query on the async connection did not return the expected result.";
        }
    }


    private function checkFailureReason() : void
    {
        $throwOnEveryWarning = function (int $severity, string $message) : never {
            throw new \ErrorException($message, 0, $severity);
        };

        set_error_handler($throwOnEveryWarning);

        try
        {
            PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, 'definitely the wrong password', forceNew: true, port: $this->getPort());
            $this->m_errorMessages[] = "Connecting with the wrong password did not throw.";
        }
        catch (ExceptionConnectionError $ex)
        {
            if (str_contains($ex->getMessage(), 'password authentication failed') === false)
            {
                $this->m_errorMessages[] = "The connection error didn't include PostgreSQL's reason: {$ex->getMessage()}";
            }
        }
        catch (\ErrorException $ex)
        {
            $this->m_errorMessages[] = "A PHP warning was raised before ExceptionConnectionError: {$ex->getMessage()}";
        }
        finally
        {
            restore_error_handler();
        }
    }


    /**
     * Connect as a role whose name and password contain spaces, quotes and a backslash.
     */
    private function checkQuotedValues() : void
    {
        $db = ConnectionHandler::getDb();
        $escapedUser = $db->escapeIdentifier(self::ODD_USER);
        $db->query("DROP ROLE IF EXISTS {$escapedUser}");
        $db->query("CREATE ROLE {$escapedUser} LOGIN PASSWORD {$db->escapeValue(self::ODD_PASSWORD)}");

        try
        {
            $oddDb = PgSqlConnection::create(DB_HOST, DB_NAME, self::ODD_USER, self::ODD_PASSWORD, forceNew: true, port: $this->getPort());
            $currentUser = pg_fetch_result($oddDb->query('SELECT current_user'), 0, 0);

            if ($currentUser !== self::ODD_USER)
            {
                $this->m_errorMessages[] = "Connected as the wrong user: {$currentUser}";
            }

            pg_close($oddDb->getResource());
        }
        catch (ExceptionConnectionError $ex)
        {
            $this->m_errorMessages[] = "Couldn't connect with a user and password that need quoting: {$ex->getMessage()}";
        }
        finally
        {
            $db->query("DROP ROLE IF EXISTS {$escapedUser}");
        }
    }


    private function getPort() : int
    {
        return defined('DB_PORT') ? DB_PORT : 5432;
    }
}
