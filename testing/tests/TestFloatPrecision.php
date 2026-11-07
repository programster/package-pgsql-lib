<?php

/*
 * Tests that floats are escaped with full precision, rather than PHP's default 14 significant digits, and that they
 * survive a round trip through inserts and where clauses.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgSqlConnection;


class TestFloatPrecision extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that floats are escaped without losing precision.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $this->checkEscapedValues($db);
        $this->checkRoundTrip($db);
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function checkEscapedValues(PgSqlConnection $db) : void
    {
        # [value, expected]
        $cases = [
            [0.1 + 0.2, '0.30000000000000004'],
            [1234567890.123456789, '1234567890.1234567'],
            [1.5, '1.5'],
            [3.0, '3.0'],
            [-2.25, '-2.25'],
            [1e25, '1.0E+25'],
            [1e-7, '1.0E-7'],
        ];

        foreach ($cases as [$value, $expected])
        {
            $actual = $db->escapeValue($value);

            if ($actual !== $expected)
            {
                $this->m_errorMessages[] = "escapeValue(" . var_export($value, true) . "): expected '{$expected}' but got " . var_export($actual, true);
            }
        }

        # The result doesn't depend on the serialize_precision ini setting, which is restored afterwards.
        $previousPrecision = ini_set('serialize_precision', '14');
        $withLowPrecision = $db->escapeValue(0.1 + 0.2);
        $precisionAfterwards = ini_get('serialize_precision');
        ini_set('serialize_precision', $previousPrecision);

        if ($withLowPrecision !== '0.30000000000000004')
        {
            $this->m_errorMessages[] = "With serialize_precision set to 14, escapeValue(0.1 + 0.2) gave {$withLowPrecision}";
        }

        if ($precisionAfterwards !== '14')
        {
            $this->m_errorMessages[] = "escapeValue() didn't restore serialize_precision, which was {$precisionAfterwards}";
        }

        # Ints are still returned unchanged.
        if ($db->escapeValue(42) !== 42)
        {
            $this->m_errorMessages[] = "escapeValue(42) did not return the int 42";
        }
    }


    /**
     * Each float is inserted into a double precision column, read back, and matched with a where clause.
     */
    private function checkRoundTrip(PgSqlConnection $db) : void
    {
        $values = [0.1 + 0.2, 1234567890.123456789, 1e25, 1e-7, -2.25];
        $db->query('CREATE TEMPORARY TABLE "float_test" ("id" int NOT NULL, "value" double precision NOT NULL)');

        $rows = [];

        foreach ($values as $index => $value)
        {
            $rows[] = ['id' => $index, 'value' => $value];
        }

        $db->query($db->generateBatchInsertQuery('float_test', $rows));

        foreach ($values as $index => $value)
        {
            $result = $db->query($db->generateSelectWhereQuery('float_test', ['value' => $value], Conjunction::AND));
            $ids = pg_fetch_all_columns($result, pg_field_num($result, 'id'));
            $readBack = (pg_num_rows($result) === 1) ? (float)pg_fetch_result($result, 0, 'value') : null;

            if ($ids !== [(string)$index] || $readBack !== $value)
            {
                $this->m_errorMessages[] =
                    "float " . var_export($value, true) . ": where clause matched ids " . json_encode($ids)
                    . " and read back " . var_export($readBack, true);
            }
        }

        $db->query('DROP TABLE "float_test"');
    }
}
