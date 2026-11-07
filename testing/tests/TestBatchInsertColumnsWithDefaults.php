<?php

/*
 * Tests how generateBatchInsertQuery() handles rows that don't all have the same columns:
 * - a missing column that is not declared in $columnsWithDefaults throws ExceptionMismatchedRows,
 * - a missing column that is declared gets DEFAULT, so PostgreSQL fills in the column's default,
 * - an explicit null still inserts NULL rather than the default.
 */

use Programster\PgsqlLib\Exceptions\ExceptionMismatchedRows;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\Table;


class TestBatchInsertColumnsWithDefaults extends AbstractTest
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "defaults_test"');
    }


    public function getDescription() : string
    {
        return "Test that batch insert only fills in missing columns with DEFAULT when they are declared.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $conn = $db->getResource();

        $this->checkMismatchedRowsThrow($db, $conn);
        $this->checkGeneratedSql($db, $conn);
        $this->checkDefaultsAreApplied($db);

        $this->m_passed = (count($this->m_errorMessages) === 0);
        $db->query('TRUNCATE "defaults_test"');
    }


    /**
     * Rows with missing columns that are not declared as having defaults must throw, whichever row is missing them.
     */
    private function checkMismatchedRowsThrow(\Programster\PgsqlLib\PgSqlConnection $db, \PgSql\Connection $conn) : void
    {
        $alice = ['id' => '44444444-0000-0000-0000-000000000001', 'name' => 'alice', 'status' => 'admin'];
        $bob = ['id' => '44444444-0000-0000-0000-000000000002', 'name' => 'bob'];
        $carol = ['id' => '44444444-0000-0000-0000-000000000003', 'nickname' => 'caz', 'status' => 'admin'];

        $attempts = [
            'later row missing a column, no defaults' => function () use ($db, $alice, $bob) : string {
                return $db->generateBatchInsertQuery('defaults_test', [$alice, $bob]);
            },
            'first row missing a column, no defaults' => function () use ($db, $alice, $bob) : string {
                return $db->generateBatchInsertQuery('defaults_test', [$bob, $alice]);
            },
            'same column count, different names' => function () use ($db, $alice, $carol) : string {
                return $db->generateBatchInsertQuery('defaults_test', [$alice, $carol], ['nickname']);
            },
            'missing column not in the defaults list' => function () use ($conn, $alice, $bob) : string {
                return PgsqlLib::generateBatchInsertQuery($conn, 'defaults_test', [$alice, $bob], ['nickname']);
            },
        ];

        foreach ($attempts as $label => $attempt)
        {
            try
            {
                $query = $attempt();
                $this->m_errorMessages[] = "{$label}: expected ExceptionMismatchedRows, but got the query {$query}";
            }
            catch (ExceptionMismatchedRows $ex)
            {
                // expected
            }
        }
    }


    /**
     * Declared missing columns become DEFAULT, wherever they are missing, and explicit nulls stay NULL.
     */
    private function checkGeneratedSql(\Programster\PgsqlLib\PgSqlConnection $db, \PgSql\Connection $conn) : void
    {
        $rows = [
            ['name' => 'bob', 'id' => 'b'],
            ['status' => 'admin', 'name' => 'alice', 'id' => 'a', 'nickname' => null],
        ];

        $expected =
            'INSERT INTO "app"."defaults_test" ("id","name","nickname","status")'
            . ' VALUES (\'b\', \'bob\', DEFAULT, DEFAULT),(\'a\', \'alice\', NULL, \'admin\')';

        $this->assertSame($expected, PgsqlLib::generateBatchInsertQuery($conn, new Table('defaults_test', 'app'), $rows, ['status', 'nickname']), "PgsqlLib SQL");
        $this->assertSame($expected, $db->generateBatchInsertQuery(new Table('defaults_test', 'app'), $rows, ['status', 'nickname']), "PgSqlConnection SQL");

        # Declaring defaults for columns that every row provides changes nothing.
        $this->assertSame(
            'INSERT INTO "defaults_test" ("id","name") VALUES (\'x\', \'n\')',
            $db->generateBatchInsertQuery('defaults_test', [['name' => 'n', 'id' => 'x']], ['name']),
            "defaults declared but not needed"
        );
    }


    /**
     * The generated query really does give PostgreSQL's defaults for left-out columns.
     */
    private function checkDefaultsAreApplied(\Programster\PgsqlLib\PgSqlConnection $db) : void
    {
        $rows = [
            ['id' => '44444444-0000-0000-0000-000000000011', 'name' => 'alice', 'status' => 'admin', 'nickname' => 'ali'],
            ['id' => '44444444-0000-0000-0000-000000000012', 'name' => 'bob'],
            ['id' => '44444444-0000-0000-0000-000000000013', 'name' => 'carol', 'nickname' => null],
        ];

        $db->query($db->generateBatchInsertQuery('defaults_test', $rows, ['status', 'nickname']));
        $result = $db->query('SELECT "name", "status", "nickname" FROM "defaults_test" ORDER BY "name"');
        $actual = json_encode(pg_fetch_all($result));

        $expected = json_encode([
            ['name' => 'alice', 'status' => 'admin', 'nickname' => 'ali'],
            ['name' => 'bob', 'status' => 'member', 'nickname' => 'anon'],
            ['name' => 'carol', 'status' => 'member', 'nickname' => null],
        ]);

        $this->assertSame($expected, $actual, "rows in the database");
    }


    private function assertSame(string $expected, string $actual, string $label) : void
    {
        if ($expected !== $actual)
        {
            $this->m_errorMessages[] = "{$label}: expected {$expected} but got {$actual}";
        }
    }
}
