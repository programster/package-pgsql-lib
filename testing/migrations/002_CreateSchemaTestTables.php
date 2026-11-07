<?php

/*
 * Creates tables outside of the public schema, for testing schema-qualified table names.
 * - "app"."user" has the same name and structure as "public"."user", so a query that ignores the schema would hit
 *   the wrong table.
 * - "Odd.Schema""X"."Mixed.Case""Table" has names that need quoting: mixed case, a dot, and a double quote.
 */

class CreateSchemaTestTables implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $queries = [
            'CREATE SCHEMA "app";',
            'CREATE TABLE "app"."user" (
                "id" uuid NOT NULL,
                "name" varchar(255) NOT NULL,
                "email" varchar(255) NOT NULL,
                PRIMARY KEY (id)
            );',
            'CREATE SCHEMA "Odd.Schema""X";',
            'CREATE TABLE "Odd.Schema""X"."Mixed.Case""Table" (
                "id" uuid NOT NULL,
                "name" varchar(255) NOT NULL,
                PRIMARY KEY (id)
            );',
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === false)
            {
                throw new \Exception("Failed to create the schema test tables: " . pg_last_error($dbConn));
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        $result = pg_query($dbConn, 'DROP SCHEMA "app" CASCADE; DROP SCHEMA "Odd.Schema""X" CASCADE;');

        if ($result === false)
        {
            throw new \Exception("Failed to drop the schema test tables: " . pg_last_error($dbConn));
        }
    }
}
