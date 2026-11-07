<?php

/*
 * Tests that generateDeleteWhereQuery() refuses to generate a query without where pairs, as that would delete every
 * row in the table, and that the table is left untouched.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Exceptions\ExceptionNoData;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\Table;


class TestDeleteRequiresWherePairs extends AbstractTest
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "public"."user"');
    }


    public function getDescription() : string
    {
        return "Test that generateDeleteWhereQuery() throws if given no where pairs.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $conn = $db->getResource();

        $db->query($db->generateBatchInsertQuery('user', [
            ['id' => '33333333-0000-0000-0000-000000000001', 'name' => 'keep-me', 'email' => 'keep@example.com'],
        ]));

        $attempts = [
            'PgsqlLib, no schema' => function () use ($conn) : string {
                return PgsqlLib::generateDeleteWhereQuery($conn, 'user', [], Conjunction::AND);
            },
            'PgsqlLib, with schema' => function () use ($conn) : string {
                return PgsqlLib::generateDeleteWhereQuery($conn, new Table('user', 'app'), [], Conjunction::OR);
            },
            'PgSqlConnection, no schema' => function () use ($db) : string {
                return $db->generateDeleteWhereQuery('user', [], Conjunction::AND);
            },
            'PgSqlConnection, with schema' => function () use ($db) : string {
                return $db->generateDeleteWhereQuery(new Table('user', 'app'), [], Conjunction::OR);
            },
        ];

        foreach ($attempts as $label => $attempt)
        {
            try
            {
                $query = $attempt();
                $db->query($query);
                $this->m_errorMessages[] = "{$label}: expected ExceptionNoData, but got the query {$query}";
            }
            catch (ExceptionNoData $ex)
            {
                // expected
            }
        }

        # An empty list of values for a column is still allowed, and matches nothing.
        $query = $db->generateDeleteWhereQuery('user', ['id' => []], Conjunction::AND);
        $db->query($query);

        $count = (int)pg_fetch_result($db->query('SELECT count(*) FROM "public"."user"'), 0, 0);

        if ($count !== 1)
        {
            $this->m_errorMessages[] = "Expected the user table to still have 1 row, but it has {$count}";
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
        $db->query('TRUNCATE "public"."user"');
    }
}
