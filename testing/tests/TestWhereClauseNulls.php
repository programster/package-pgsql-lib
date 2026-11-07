<?php

/*
 * Tests that null values in where pairs generate IS NULL, both on their own and inside a list of values, and that
 * the generated queries match the right rows.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\PgsqlLib;


class TestWhereClauseNulls extends AbstractTest
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "defaults_test"');
    }


    public function getDescription() : string
    {
        return "Test that null values in where pairs generate IS NULL and match the right rows.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $this->checkGeneratedSql($db->getResource());
        $this->checkMatchedRows($db);
        $this->m_passed = (count($this->m_errorMessages) === 0);
        $db->query('TRUNCATE "defaults_test"');
    }


    private function checkGeneratedSql(\PgSql\Connection $conn) : void
    {
        # [wherePairs, conjunction, expected]
        $cases = [
            [['nickname' => null], Conjunction::AND, 'WHERE "nickname" IS NULL'],
            [['nickname' => [null]], Conjunction::AND, 'WHERE "nickname" IS NULL'],
            [['nickname' => [null, null]], Conjunction::AND, 'WHERE "nickname" IS NULL'],
            [['nickname' => ['ali', null]], Conjunction::AND, 'WHERE ("nickname" IN(\'ali\') OR "nickname" IS NULL)'],
            [['nickname' => [null, 'ali', 'caz']], Conjunction::AND, 'WHERE ("nickname" IN(\'ali\',\'caz\') OR "nickname" IS NULL)'],
            [['nickname' => null, 'name' => 'bob'], Conjunction::OR, 'WHERE "nickname" IS NULL OR "name" = \'bob\''],

            # Unchanged behaviour without nulls.
            [['nickname' => 'ali'], Conjunction::AND, 'WHERE "nickname" = \'ali\''],
            [['nickname' => ['ali', 'caz']], Conjunction::AND, 'WHERE "nickname" IN(\'ali\',\'caz\')'],
            [['nickname' => []], Conjunction::AND, 'WHERE FALSE'],
        ];

        foreach ($cases as [$wherePairs, $conjunction, $expected])
        {
            $actual = PgsqlLib::generateWhereClause($conn, $wherePairs, $conjunction);

            if ($actual !== $expected)
            {
                $this->m_errorMessages[] = "where clause for " . json_encode($wherePairs) . ": expected {$expected} but got {$actual}";
            }
        }
    }


    private function checkMatchedRows(PgSqlConnection $db) : void
    {
        $db->query($db->generateBatchInsertQuery('defaults_test', [
            ['id' => '55555555-0000-0000-0000-000000000001', 'name' => 'alice', 'nickname' => 'ali'],
            ['id' => '55555555-0000-0000-0000-000000000002', 'name' => 'bob', 'nickname' => null],
            ['id' => '55555555-0000-0000-0000-000000000003', 'name' => 'carol', 'nickname' => 'caz'],
            ['id' => '55555555-0000-0000-0000-000000000004', 'name' => 'dave', 'nickname' => null],
        ]));

        # [label, wherePairs, conjunction, expected names]
        $cases = [
            ['null value', ['nickname' => null], Conjunction::AND, ['bob', 'dave']],
            ['list of only nulls', ['nickname' => [null]], Conjunction::AND, ['bob', 'dave']],
            ['list with a null', ['nickname' => ['ali', null]], Conjunction::AND, ['alice', 'bob', 'dave']],

            # Without the brackets, this would be: IN('ali') OR (IS NULL AND name = 'bob'), which also matches alice.
            ['list with a null, ANDed', ['nickname' => ['ali', null], 'name' => 'bob'], Conjunction::AND, ['bob']],
            ['null ORed', ['nickname' => null, 'name' => 'carol'], Conjunction::OR, ['bob', 'carol', 'dave']],
        ];

        foreach ($cases as [$label, $wherePairs, $conjunction, $expected])
        {
            $result = $db->query($db->generateSelectWhereQuery('defaults_test', $wherePairs, $conjunction));
            $this->assertNames($expected, $result, "select, {$label}");
        }

        $db->query($db->generateDeleteWhereQuery('defaults_test', ['nickname' => null], Conjunction::AND));
        $this->assertNames(['alice', 'carol'], $db->query('SELECT "name" FROM "defaults_test"'), "delete where null");
    }


    private function assertNames(array $expected, \PgSql\Result $result, string $label) : void
    {
        $actual = pg_fetch_all_columns($result, pg_field_num($result, 'name'));
        sort($actual);

        if ($actual !== $expected)
        {
            $this->m_errorMessages[] = "{$label}: expected " . json_encode($expected) . " but got " . json_encode($actual);
        }
    }
}
