<?php

/*
 * Provides the database connections that the tests use.
 */

class ConnectionHandler
{
    /**
     * Get the connection to the test database, creating it if it doesn't already exist.
     * @return \Programster\PgsqlLib\PgSqlConnection
     */
    public static function getDb() : Programster\PgsqlLib\PgSqlConnection
    {
        static $db = null;

        if ($db === null)
        {
            $db = self::createNewConnection();
        }

        return $db;
    }


    /**
     * Create a new connection to the test database, separate from getDb(), e.g. to see what other sessions can see.
     * @return \Programster\PgsqlLib\PgSqlConnection
     */
    public static function createNewConnection() : Programster\PgsqlLib\PgSqlConnection
    {
        // DB_PORT is optional, so that settings files written before it existed still work.
        $port = defined('DB_PORT') ? (int)DB_PORT : 5432;

        return Programster\PgsqlLib\PgSqlConnection::create(
            DB_HOST,
            DB_NAME,
            DB_USER,
            DB_PASSWORD,
            forceNew: true,
            port: $port
        );
    }
}
