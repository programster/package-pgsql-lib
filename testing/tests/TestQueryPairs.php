<?php

/*
 * Tests generateQueryPairs(), and how the generators handle integer array keys, which PHP creates for list arrays
 * and for numeric string keys such as "2024".
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Exceptions\ExceptionNoData;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\PgsqlLib;


class TestQueryPairs extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test generateQueryPairs() and integer column name keys.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $conn = $db->getResource();

        $this->assertSame('"name" = \'bob\', "nickname" = NULL', $db->generateQueryPairs(['name' => 'bob', 'nickname' => null]), "query pairs");

        # The wrapper passes escapeIdentifiers through.
        $this->assertSame('name = \'bob\'', $db->generateQueryPairs(['name' => 'bob'], true, false), "wrapper, identifiers not escaped");
        $this->assertSame('"name" = bob', $db->generateQueryPairs(['name' => 'bob'], false), "wrapper, values not escaped");

        $emptyPairsAttempts = [
            'PgsqlLib' => function () use ($conn) : string {
                return PgsqlLib::generateQueryPairs($conn, []);
            },
            'PgSqlConnection' => function () use ($db) : string {
                return $db->generateQueryPairs([]);
            },
        ];

        foreach ($emptyPairsAttempts as $label => $attempt)
        {
            try
            {
                $attempt();
                $this->m_errorMessages[] = "{$label}: empty query pairs did not throw";
            }
            catch (ExceptionNoData $ex)
            {
                // expected
            }
        }

        # Integer keys are treated as column names, rather than causing a TypeError.
        $this->assertSame('"2024" = 5', $db->generateQueryPairs(['2024' => 5]), "numeric string key in query pairs");
        $this->assertSame('WHERE "2024" = 5', PgsqlLib::generateWhereClause($conn, ['2024' => 5], Conjunction::AND), "numeric string key in where clause");
        $this->assertSame('INSERT INTO "t" ("2024") VALUES (5)', $db->generateBatchInsertQuery('t', [['2024' => 5]]), "numeric string key in batch insert");
        $this->assertSame(['"0"', '"name"'], $db->escapeIdentifiers([0, 'name']), "escapeIdentifiers with an int");

        # A list row by mistake gets a clear error from PostgreSQL, about the column "0".
        try
        {
            $db->query($db->generateBatchInsertQuery('defaults_test', [['a', 'b']]));
            $this->m_errorMessages[] = "inserting a list row did not throw";
        }
        catch (ExceptionQueryError $ex)
        {
            if (str_contains($ex->getErrorMessage(), 'column "0"') === false)
            {
                $this->m_errorMessages[] = "inserting a list row gave an unclear error: {$ex->getErrorMessage()}";
            }
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function assertSame(mixed $expected, mixed $actual, string $label) : void
    {
        if ($expected !== $actual)
        {
            $this->m_errorMessages[] = "{$label}: expected " . var_export($expected, true) . " but got " . var_export($actual, true);
        }
    }
}
