<?php

/*
 * Tests escapeTableName() on both PgsqlLib and PgSqlConnection, and checks that the query generators produce
 * exactly the same SQL as before when no schema is provided.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTableName;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\Table;


class TestEscapeTableName extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that table names are escaped correctly, with and without a schema.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $conn = $db->getResource();

        # [tableName, schemaName, expected]
        $cases = [
            ['user', null, '"user"'],
            ['user', 'app', '"app"."user"'],
            ['MyTable', null, '"MyTable"'],
            ['MyTable', 'MySchema', '"MySchema"."MyTable"'],
            ['my.table', null, '"my.table"'],
            ['my.table', 'my.schema', '"my.schema"."my.table"'],
            ['we"ird', null, '"we""ird"'],
            ['we"ird', 'sch"ema', '"sch""ema"."we""ird"'],
            ['Mixed.Case"Table', 'Odd.Schema"X', '"Odd.Schema""X"."Mixed.Case""Table"'],
        ];

        foreach ($cases as [$tableName, $schemaName, $expected])
        {
            $label = "table " . json_encode($tableName) . ", schema " . json_encode($schemaName);
            $table = new Table($tableName, $schemaName);
            $this->assertSame($expected, PgsqlLib::escapeTableName($conn, $table), "PgsqlLib, {$label}");
            $this->assertSame($expected, $db->escapeTableName($table), "PgSqlConnection, {$label}");

            if ($schemaName === null)
            {
                # A plain string is the same as a Table without a schema.
                $this->assertSame($expected, PgsqlLib::escapeTableName($conn, $tableName), "PgsqlLib string, {$label}");
                $this->assertSame($expected, $db->escapeTableName($tableName), "PgSqlConnection string, {$label}");
            }
        }

        # Without a schema, it's identical to the old single pg_escape_identifier() call.
        $this->assertSame(PgsqlLib::escapeIdentifier($conn, 'user'), PgsqlLib::escapeTableName($conn, 'user'), "no schema matches escapeIdentifier");

        # A dotted string is one identifier, not a schema and table.
        $this->assertSame('"app.user"', $db->escapeTableName('app.user'), "dotted string");

        # Table's accessors.
        $qualified = new Table('user', 'app');
        $unqualified = new Table('user');
        $this->assertSame('user', $qualified->getName(), "Table::getName()");
        $this->assertSame('app', $qualified->getSchemaName(), "Table::getSchemaName()");
        $this->assertSame('true', var_export($qualified->hasSchemaName(), true), "Table::hasSchemaName() with a schema");
        $this->assertSame('false', var_export($unqualified->hasSchemaName(), true), "Table::hasSchemaName() without a schema");

        try
        {
            $unqualified->getSchemaName();
            $this->m_errorMessages[] = "Table::getSchemaName() without a schema did not throw";
        }
        catch (\LogicException $ex)
        {
            // expected
        }

        # Empty names are rejected, as they would generate invalid SQL.
        $emptyNameAttempts = [
            'empty table name' => function () : Table {
                return new Table('');
            },
            'empty schema name' => function () : Table {
                return new Table('user', '');
            },
            'empty string table' => function () use ($db) : string {
                return $db->escapeTableName('');
            },
        ];

        foreach ($emptyNameAttempts as $label => $attempt)
        {
            try
            {
                $attempt();
                $this->m_errorMessages[] = "{$label} did not throw";
            }
            catch (ExceptionInvalidTableName $ex)
            {
                // expected
            }
        }

        # Selecting without where pairs selects every row, with no trailing space.
        $this->assertSame('SELECT * FROM "user"', $db->generateSelectWhereQuery('user', [], Conjunction::AND), "select without where pairs");

        # Without a schema, the generators produce exactly the SQL they did before this change.
        $wherePairs = ['email' => 'a@b.com'];
        $rows = [['name' => 'n', 'id' => 'x']];

        $this->assertSame(
            'SELECT * FROM "user" WHERE "email" = \'a@b.com\'',
            $db->generateSelectWhereQuery('user', $wherePairs, Conjunction::AND),
            "select without schema"
        );

        $this->assertSame(
            'DELETE FROM "user" WHERE "email" = \'a@b.com\'',
            $db->generateDeleteWhereQuery('user', $wherePairs, Conjunction::AND),
            "delete without schema"
        );

        $this->assertSame(
            'INSERT INTO "user" ("id","name") VALUES (\'x\', \'n\')',
            $db->generateBatchInsertQuery('user', $rows),
            "batch insert without schema"
        );

        # With a schema, only the table reference changes.
        $this->assertSame(
            'SELECT * FROM "app"."user" WHERE "email" = \'a@b.com\'',
            PgsqlLib::generateSelectWhereQuery($conn, new Table('user', 'app'), $wherePairs, Conjunction::AND),
            "select with schema"
        );

        $this->assertSame(
            'DELETE FROM "app"."user" WHERE "email" = \'a@b.com\'',
            PgsqlLib::generateDeleteWhereQuery($conn, new Table('user', 'app'), $wherePairs, Conjunction::AND),
            "delete with schema"
        );

        $this->assertSame(
            'INSERT INTO "app"."user" ("id","name") VALUES (\'x\', \'n\')',
            PgsqlLib::generateBatchInsertQuery($conn, new Table('user', 'app'), $rows),
            "batch insert with schema"
        );

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function assertSame(string $expected, string $actual, string $label) : void
    {
        if ($expected !== $actual)
        {
            $this->m_errorMessages[] = "{$label}: expected {$expected} but got {$actual}";
        }
    }
}
