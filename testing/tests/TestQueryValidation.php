<?php

/*
 * Tests that a Transaction accepts single statements however they're quoted, and rejects where they're added anything
 * that couldn't work in a transaction.
 */

use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;
use Programster\PgsqlLib\Query;
use Programster\PgsqlLib\QueryCollection;
use Programster\PgsqlLib\Transaction;


class TestQueryValidation extends AbstractTest
{
    private const VALID = [
        'SELECT 1',
        'SELECT 1;',
        'SELECT 1 ; ; ',
        "SELECT ';'",
        "SELECT 'it''s; fine'",
        'SELECT "a;b" FROM t',
        "SELECT E'it\\'s; fine'",
        "SELECT e'a'\n'b\\'c;'",
        "SELECT U&'d\\0061t;a'",
        'SELECT $$a;b$$',
        'SELECT $tag$ ; $$ ; $tag$',
        'SELECT foo$1 FROM t',
        "SELECT 1 -- comment; here",
        "SELECT 1 /* a /* ; */ b */",
        "/* first */ SELECT 1; -- the end",
        "SELECT 'COMMIT'",
        'UPDATE "commit" SET "a" = 1',
        'SELECT * FROM t WHERE a = 1 - -1',
        "SELECT E'\\'; DROP TABLE x; --'",
        'CREATE INDEX i ON t (a)',
        'CREATE RULE r AS ON INSERT TO t DO ALSO (INSERT INTO a VALUES (1); INSERT INTO b VALUES (2))',
        'CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; SELECT CASE WHEN true THEN 2 END; END',
        'CREATE OR REPLACE PROCEDURE p() LANGUAGE sql BEGIN ATOMIC INSERT INTO t VALUES (1); END;',
        'CREATE FUNCTION g(int) RETURNS int LANGUAGE sql RETURN $1',
        'PREPARE p AS SELECT $1',
        "COPY t FROM '/tmp/data.csv'",
        "COPY (SELECT 'stdin') TO '/tmp/out.csv'",

        // long enough for the validator's fast path, which skips over words once it has read the first few.
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = ';' AND y = 'it''s;';",
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = E'it\\'s; fine' -- done;",
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = e'a'\n'b\\'c;'",
        'SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = $tag$ ; $$ ; $tag$ /* ; */',
        'SELECT a, b, c, d, e, f, g, h, i, j, k, foo$1, "x;y" FROM t',
        "SELECT a, b, c, d, e, f, g, h, i, j, k, type'not; escaped' FROM t",
    ];

    private const INVALID = [
        '',
        '   ',
        ';',
        '-- only a comment',
        'SELECT 1; SELECT 2',
        "SELECT 1; -- ok\nDROP TABLE t",
        "SELECT 'it''s'; DROP TABLE t",
        "SELECT 'never closed",
        'SELECT "never closed',
        'SELECT $$never closed',
        'SELECT $a$ never closed $b$',
        'SELECT /* never closed',
        'SELECT /* /* only one closed */',
        "SELECT E'never closed\\'",
        'BEGIN',
        'begin transaction',
        '/* hidden */ COMMIT',
        'START TRANSACTION',
        'COMMIT',
        'END',
        'ROLLBACK',
        'ABORT',
        'SAVEPOINT a',
        'RELEASE a',
        "PREPARE TRANSACTION 'x'",
        'VACUUM',
        'vacuum full t',
        'CREATE DATABASE x',
        'DROP DATABASE x',
        "CREATE TABLESPACE x LOCATION '/tmp'",
        'DROP TABLESPACE x',
        'ALTER DATABASE x SET TABLESPACE y',
        'CREATE INDEX CONCURRENTLY i ON t (a)',
        'CREATE UNIQUE INDEX CONCURRENTLY i ON t (a)',
        'DROP INDEX CONCURRENTLY i',
        'REINDEX DATABASE x',
        'REINDEX SYSTEM x',
        'REINDEX (CONCURRENTLY) TABLE t',
        'ALTER SYSTEM SET work_mem = 1',
        'DISCARD ALL',
        'COPY t FROM STDIN',
        'COPY t (a, b, c, d, e, f, g, h, i) TO STDOUT',
        'SELECT $1',

        // long enough for the validator's fast path, which skips over words once it has read the first few.
        'SELECT a, b, c, d, e, f, g, h, i, j, k FROM t; DROP TABLE t',
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = 'y'; DROP TABLE t",
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = 'never closed",
        "SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = E'never closed\\'",
        'SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = $$never closed',
        'SELECT a, b, c, d, e, f, g, h, i, j, k FROM t /* never closed',
        'SELECT a, b, c, d, e, f, g, h, i, j, k FROM t WHERE x = $1',
    ];


    public function getDescription() : string
    {
        return "Test that a Transaction accepts single statements, and rejects anything that can't work in one.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        foreach (self::VALID as $sql)
        {
            try
            {
                new Query($sql);
            }
            catch (ExceptionInvalidQuery $ex)
            {
                $this->m_errorMessages[] = "Rejected valid SQL [{$sql}]: {$ex->getMessage()}";
            }
        }

        foreach (self::INVALID as $sql)
        {
            try
            {
                new Query($sql);
                $this->m_errorMessages[] = "Accepted invalid SQL [{$sql}]";
            }
            catch (ExceptionInvalidQuery)
            {
                // expected.
            }

            try
            {
                (new Transaction($db, []))->append('SELECT 1', $sql);
                $this->m_errorMessages[] = "append() accepted invalid SQL [{$sql}]";
            }
            catch (ExceptionInvalidQuery)
            {
                // expected.
            }

            $this->checkCollectionRejects($sql);
        }

        $this->checkParams();
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function checkParams() : void
    {
        $query = new Query('SELECT $1, $2, $1', ['a', null]);

        if ($query->hasParams() === false || $query->getParams() !== ['a', null])
        {
            $this->m_errorMessages[] = "The query didn't keep its params.";
        }

        foreach ([['SELECT $1, $2', [1]], ['SELECT 1', [1]], ['SELECT $2', [1]], ['SELECT $1', []]] as [$sql, $params])
        {
            try
            {
                new Query($sql, $params);
                $this->m_errorMessages[] = "Accepted mismatched placeholders: [{$sql}]";
            }
            catch (ExceptionInvalidQuery)
            {
                // expected.
            }
        }

        foreach ([['key' => 'a'], [new \stdClass()], [['nested']]] as $badParams)
        {
            try
            {
                new Query('SELECT $1', $badParams);
                $this->m_errorMessages[] = "Bad params were accepted: " . json_encode($badParams);
            }
            catch (ExceptionUnexpectedValueType)
            {
                // expected.
            }
        }
    }


    /**
     * Check that an invalid query can't get into a collection by any of the ways of adding one.
     * @param string $sql - the invalid query.
     * @return void
     */
    private function checkCollectionRejects(string $sql) : void
    {
        $collection = new QueryCollection(new Query('SELECT 1'));

        $ways = [
            'add()' => function () use ($collection, $sql) : void { $collection->add($sql); },
            '[]' => function () use ($collection, $sql) : void { $collection[] = $sql; },
            '[0]' => function () use ($collection, $sql) : void { $collection[0] = $sql; },
        ];

        foreach ($ways as $name => $addQuery)
        {
            try
            {
                $addQuery();
                $this->m_errorMessages[] = "{$name} accepted invalid SQL [{$sql}]";
            }
            catch (ExceptionInvalidQuery)
            {
                // expected.
            }
        }

        if (count($collection) !== 1 || $collection[0]->getSql() !== 'SELECT 1')
        {
            $this->m_errorMessages[] = "A rejected query changed the collection: [{$sql}]";
        }
    }
}
