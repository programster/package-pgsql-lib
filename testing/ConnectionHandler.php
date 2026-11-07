<?php

/*
 * Provides the database connection that the tests use.
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
            // DB_PORT is optional, so that settings files written before it existed still work.
            $port = defined('DB_PORT') ? DB_PORT : 5432;

            $db = Programster\PgsqlLib\PgSqlConnection::create(
                DB_HOST,
                DB_NAME,
                DB_USER,
                DB_PASSWORD,
                forceNew: true,
                port: $port
            );
        }

        return $db;
    }
}
