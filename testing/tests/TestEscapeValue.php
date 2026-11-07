<?php

/*
 * Tests the form escapeValue() and escapeValues() return each type of value in, and that null becomes NULL in the
 * generated queries.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Exceptions\AbstractException;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;
use Programster\PgsqlLib\PgsqlLib;


enum TestEscapeValueStatus : string
{
    case Active = 'active';
    case OBrien = "o'brien";
}


enum TestEscapeValuePriority : int
{
    case High = 1;
}


enum TestEscapeValueUnbacked
{
    case Something;
}


class TestEscapeValue extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that escapeValue() returns each type in the form the database expects.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $conn = $db->getResource();

        # [label, value, expected]
        $cases = [
            ['string', 'bob', "'bob'"],
            ['string with a quote', "o'brien", "'o''brien'"],
            ['numeric string', '5', "'5'"],
            ['int', 5, 5],
            ['negative int', -3, -3],
            ['float', 1.5, '1.5'],
            ['true', true, 'TRUE'],
            ['false', false, 'FALSE'],
            ['null', null, 'NULL'],
            ['string backed enum', TestEscapeValueStatus::Active, "'active'"],
            ['string backed enum with a quote', TestEscapeValueStatus::OBrien, "'o''brien'"],
            ['int backed enum', TestEscapeValuePriority::High, 1],
        ];

        foreach ($cases as [$label, $value, $expected])
        {
            $this->assertSame($expected, PgsqlLib::escapeValue($conn, $value), "PgsqlLib::escapeValue, {$label}");
            $this->assertSame($expected, $db->escapeValue($value), "PgSqlConnection::escapeValue, {$label}");
        }

        $this->assertSame(
            ['a' => "'x'", 'b' => 'NULL', 'c' => 7],
            $db->escapeValues(['a' => 'x', 'b' => null, 'c' => 7]),
            "escapeValues keeps keys and converts null"
        );

        # Unsupported types throw, with a message naming the type. The exception is also an AbstractException.
        foreach (['an array' => ['an', 'array'], 'an unbacked enum' => TestEscapeValueUnbacked::Something, 'an object' => new \stdClass()] as $label => $value)
        {
            try
            {
                $db->escapeValue($value);
                $this->m_errorMessages[] = "escapeValue of {$label} did not throw";
            }
            catch (ExceptionUnexpectedValueType $ex)
            {
                if (str_contains($ex->getMessage(), get_debug_type($value)) === false || ($ex instanceof AbstractException) === false)
                {
                    $this->m_errorMessages[] = "escapeValue of {$label} threw an unexpected exception: {$ex->getMessage()}";
                }
            }
        }

        $this->assertSame(
            'WHERE "status" IN(\'active\',\'o\'\'brien\')',
            PgsqlLib::generateWhereClause($conn, ['status' => [TestEscapeValueStatus::Active, TestEscapeValueStatus::OBrien]], Conjunction::AND),
            "enums in a where clause"
        );

        # Null still generates NULL in the generated queries.
        $this->assertSame(
            '"name" = NULL, "status" = \'x\'',
            $db->generateQueryPairs(['name' => null, 'status' => 'x']),
            "query pairs with a null"
        );

        $this->assertSame(
            '"name" = NULL',
            PgsqlLib::generateQueryPairs($conn, ['name' => null], false),
            "query pairs with an unescaped null"
        );

        $this->assertSame(
            'INSERT INTO "defaults_test" ("id","nickname") VALUES (\'x\', NULL)',
            $db->generateBatchInsertQuery('defaults_test', [['id' => 'x', 'nickname' => null]]),
            "batch insert with a null"
        );

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
