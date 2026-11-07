<?php

/*
 * Creates the "user" table in the public schema, which most of the tests use.
 */

class CreateTestTables implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $createUserTableQuery =
            'CREATE TABLE "user" (
                "id" uuid NOT NULL,
                "name" varchar(255) NOT NULL,
                "email" varchar(255) NOT NULL,
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $createUserTableQuery);

        if ($result === false)
        {
            throw new \Exception("Failed to create the user table: " . pg_last_error($dbConn));
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        $result = pg_query($dbConn, 'DROP TABLE "user";');

        if ($result === false)
        {
            throw new \Exception("Failed to drop the user table: " . pg_last_error($dbConn));
        }
    }
}
