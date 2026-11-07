<?php

/*
 * Tests that INF, -INF and NAN are escaped in a form PostgreSQL accepts, survive a round trip through inserts and
 * where clauses, and are rejected by integer columns with an ExceptionQueryError.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\PgSqlConnection;


class TestSpecialFloats extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that infinite and NaN floats are escaped in a form PostgreSQL accepts.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $this->checkEscapedValues($db);
        $this->checkRoundTrip($db);
        $this->checkCastWorksOutsideColumns($db);
        $this->checkIntegerColumnRejects($db);
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function checkEscapedValues(PgSqlConnection $db) : void
    {
        $cases = [
            ["'Infinity'::float8", INF],
            ["'-Infinity'::float8", -INF],
            ["'NaN'::float8", NAN],
        ];

        foreach ($cases as [$expected, $value])
        {
            $actual = $db->escapeValue($value);

            if ($actual !== $expected)
            {
                $this->m_errorMessages[] = "escapeValue(" . var_export($value, true) . "): expected {$expected} but got {$actual}";
            }
        }
    }


    /**
     * Each value is inserted into real, double precision and numeric columns, then selected back with a where clause
     * on each column.
     */
    private function checkRoundTrip(PgSqlConnection $db) : void
    {
        $db->query(
            'CREATE TEMPORARY TABLE "special_float_test" (
                "id" int NOT NULL,
                "as_real" real NOT NULL,
                "as_double" double precision NOT NULL,
                "as_numeric" numeric NOT NULL
            )'
        );

        $values = [1 => INF, 2 => -INF, 3 => NAN];
        $rows = [];

        foreach ($values as $id => $value)
        {
            $rows[] = ['id' => $id, 'as_real' => $value, 'as_double' => $value, 'as_numeric' => $value];
        }

        $db->query($db->generateBatchInsertQuery('special_float_test', $rows));

        foreach ($values as $id => $value)
        {
            foreach (['as_real', 'as_double', 'as_numeric'] as $column)
            {
                $label = var_export($value, true) . " in {$column}";
                $query = $db->generateSelectWhereQuery('special_float_test', [$column => $value], Conjunction::AND);
                $result = $db->query($query);
                $ids = pg_fetch_all_columns($result, pg_field_num($result, 'id'));

                if ($ids !== [(string)$id])
                {
                    $this->m_errorMessages[] = "{$label}: where clause matched ids " . json_encode($ids);
                }
                else
                {
                    $readBack = $this->parsePgFloat(pg_fetch_result($result, 0, $column));

                    if (var_export($readBack, true) !== var_export($value, true))
                    {
                        $this->m_errorMessages[] = "{$label}: read back " . var_export($readBack, true);
                    }
                }
            }
        }

        $db->query('DROP TABLE "special_float_test"');
    }


    /**
     * The cast means the value is a float even where there is no column to infer the type from.
     */
    private function checkCastWorksOutsideColumns(PgSqlConnection $db) : void
    {
        $result = $db->query("SELECT ({$db->escapeValue(INF)} + 1) > 1e308 AS \"is_huge\", pg_typeof({$db->escapeValue(NAN)}) AS \"type\"");
        $row = pg_fetch_assoc($result);

        if ($row['is_huge'] !== 't' || $row['type'] !== 'double precision')
        {
            $this->m_errorMessages[] = "casts outside of columns: got " . json_encode($row);
        }
    }


    private function checkIntegerColumnRejects(PgSqlConnection $db) : void
    {
        $db->query('CREATE TEMPORARY TABLE "int_test" ("value" int NOT NULL)');

        try
        {
            $db->query($db->generateBatchInsertQuery('int_test', [['value' => INF]]));
            $this->m_errorMessages[] = "inserting INF into an integer column did not throw";
        }
        catch (ExceptionQueryError $ex)
        {
            // expected
        }

        $db->query('DROP TABLE "int_test"');
    }


    /**
     * PHP's (float) cast doesn't understand PostgreSQL's names for the special values, e.g. (float)"Infinity" is 0.
     */
    private function parsePgFloat(string $text) : float
    {
        $specialValues = ['Infinity' => INF, '-Infinity' => -INF, 'NaN' => NAN];
        return array_key_exists($text, $specialValues) ? $specialValues[$text] : (float)$text;
    }
}
