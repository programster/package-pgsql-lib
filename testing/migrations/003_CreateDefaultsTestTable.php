<?php

/*
 * Creates a table with default values, for testing batch inserts where some rows rely on those defaults.
 * - "status" is NOT NULL with a default, so leaving it out gives 'member'.
 * - "nickname" is nullable with a default, so leaving it out gives 'anon', but an explicit null gives NULL.
 */

class CreateDefaultsTestTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'CREATE TABLE "defaults_test" (
                "id" uuid NOT NULL,
                "name" varchar(255) NOT NULL,
                "status" varchar(255) NOT NULL DEFAULT \'member\',
                "nickname" varchar(255) NULL DEFAULT \'anon\',
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === false)
        {
            throw new \Exception("Failed to create the defaults_test table: " . pg_last_error($dbConn));
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        $result = pg_query($dbConn, 'DROP TABLE "defaults_test";');

        if ($result === false)
        {
            throw new \Exception("Failed to drop the defaults_test table: " . pg_last_error($dbConn));
        }
    }
}
