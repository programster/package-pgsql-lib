<?php

/*
 * Tests Transaction and PgSqlConnection::runInTransaction().
 */

use Programster\PgsqlLib\ClosureCollection;
use Programster\PgsqlLib\Exceptions\ExceptionAfterCommitFailed;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Query;
use Programster\PgsqlLib\QueryCollection;
use Programster\PgsqlLib\Transaction;


class TestTransaction extends AbstractTest
{
    private const BAD_QUERY = 'SELECT * FROM "no_such_table"';


    public function getDescription() : string
    {
        return "Test that a Transaction runs its queries together, once, and rolls them all back if one fails.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP TABLE IF EXISTS "transaction_test"; DROP SEQUENCE IF EXISTS "transaction_attempts"');
        $db->query('CREATE TABLE "transaction_test" ("id" int PRIMARY KEY)');
        $db->query('CREATE SEQUENCE "transaction_attempts"');

        try
        {
            $this->checkBuildingAndRunning();
            $this->checkAliases();
            $this->checkRunsOnce();
            $this->checkReusable();
            $this->checkRollback();
            $this->checkCommentAtEndOfQuery();
            $this->checkResults();
            $this->checkSetStatements();
            $this->checkInsideOpenTransaction();
            $this->checkAfterCommit();
            $this->checkRetries();
            $this->checkCopies();
            $this->checkQueriesWithValues();
            $this->checkAcceptedForms();
            $this->checkCallbacksInConstructor();
        }
        finally
        {
            if ($db->isInTransaction())
            {
                $db->query('ROLLBACK');
            }

            $db->query('DROP TABLE "transaction_test"; DROP SEQUENCE "transaction_attempts"');
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function checkBuildingAndRunning() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $other = ConnectionHandler::createNewConnection();
        $transaction = new Transaction($db, [$this->insert(1), $this->insert(2)]);
        $transaction->append($this->insert(3), $this->insert(4));
        $this->check("it holds what it was given", count($transaction) === 4 && count($transaction->getQueries()) === 4);
        $this->check("nothing runs before it's executed", $this->countRows() === 0);
        $this->check("it starts as not run", $transaction->hasRun() === false);
        $transaction->execute();
        $this->check("it committed", (int)pg_fetch_result($other->query('SELECT count(*) FROM "transaction_test"'), 0, 0) === 4);
        $this->check("it's marked as run", $transaction->hasRun());
        $this->check("it closed", $db->isInTransaction() === false);

        $this->reset();
        $results = $db->runInTransaction($this->insert(1), 'SELECT 5');
        $this->check("runInTransaction() runs them", $this->countRows() === 1);
        $this->check("runInTransaction() returns the results", pg_fetch_result($results[1], 0, 0) === '5');

        $empty = (new Transaction($db, []))->execute();
        $this->check("an empty transaction returns no results", count($empty) === 0);
    }


    private function checkAliases() : void
    {
        $db = ConnectionHandler::getDb();

        $aliases = [
            'commit' => function (Transaction $transaction) : void { $transaction->commit(); },
            'run' => function (Transaction $transaction) : void { $transaction->run(); },
            '__invoke' => function (Transaction $transaction) : void { $transaction(); },
        ];

        foreach ($aliases as $name => $runTransaction)
        {
            $this->reset();
            $runTransaction(new Transaction($db, [$this->insert(1)]));
            $this->check("{$name}() runs the transaction", $this->countRows() === 1);
        }
    }


    private function checkRunsOnce() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $transaction = new Transaction($db, [$this->insert(1)]);
        $transaction->execute();

        $mistakes = [
            'execute' => function () use ($transaction) : void { $transaction->execute(); },
            'commit' => function () use ($transaction) : void { $transaction->commit(); },
            'run' => function () use ($transaction) : void { $transaction->run(); },
            'invoke' => function () use ($transaction) : void { $transaction(); },
            'append' => function () use ($transaction) : void { $transaction->append('SELECT 1'); },
            'appendCallbacks' => function () use ($transaction) : void { $transaction->appendCallbacks(function () : void {}); },
            'a copy' => function () use ($transaction) : void { (clone $transaction)->execute(); },
        ];

        foreach ($mistakes as $name => $mistake)
        {
            $this->checkThrows(ExceptionInvalidTransaction::class, "{$name} after running", $mistake);
        }

        $failed = new Transaction($db, [self::BAD_QUERY]);
        $runFailed = function () use ($failed) : void { $failed->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "the failing run", $runFailed);
        $this->checkThrows(ExceptionInvalidTransaction::class, "running again after failing", $runFailed);
    }


    private function checkReusable() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $insertNext = 'INSERT INTO "transaction_test" ("id") SELECT coalesce(max("id"), 0) + 1 FROM "transaction_test"';
        $transaction = new Transaction($db, [$insertNext], isReusable: true);
        $this->check("it knows it's reusable", $transaction->isReusable());
        $transaction->execute();
        $transaction->execute();
        $transaction->append($insertNext);
        $transaction->execute();
        $this->check("it ran every time, with the added query", $this->countRows() === 4);
    }


    private function checkRollback() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();

        try
        {
            $db->runInTransaction($this->insert(1), $this->insert(2), self::BAD_QUERY, $this->insert(3));
            $this->m_errorMessages[] = "A failing transaction didn't throw.";
        }
        catch (ExceptionQueryError $ex)
        {
            $this->check("it names the failed query", $ex->getQuery() === self::BAD_QUERY);
            $this->check("it has the SQLSTATE", $ex->getSqlState() === '42P01');
        }

        $this->check("everything was rolled back", $this->countRows() === 0);
        $this->check("no transaction was left open", $db->isInTransaction() === false);
    }


    private function checkCommentAtEndOfQuery() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $results = $db->runInTransaction($this->insert(1) . ' -- no new line', $this->insert(2) . ';');
        $this->check("a comment can't swallow the next query", $this->countRows() === 2 && count($results) === 2);
    }


    private function checkResults() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();

        $results = $db->runInTransaction(
            'INSERT INTO "transaction_test" ("id") VALUES (7) RETURNING "id"',
            'SELECT 2 AS "value"'
        );

        $this->check("there's one result per query", count($results) === 2);
        $this->check("results are in order", pg_fetch_result($results[0], 0, 0) === '7');
        $this->check("results are in order", pg_fetch_result($results[1], 0, 0) === '2');
    }


    private function checkSetStatements() : void
    {
        $db = ConnectionHandler::getDb();

        $results = $db->runInTransaction(
            'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
            'SET CONSTRAINTS ALL DEFERRED',
            "SELECT current_setting('transaction_isolation')"
        );

        $this->check("SET TRANSACTION works as a query", pg_fetch_result($results[2], 0, 0) === 'serializable');
    }


    private function checkInsideOpenTransaction() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $db->query('BEGIN');
        $db->query($this->insert(1));
        $db->runInTransaction($this->insert(2));
        $this->check("the open transaction stays open", $db->isInTransaction());

        $runBad = function () use ($db) : void { $db->runInTransaction($this->insert(3), self::BAD_QUERY); };
        $this->checkThrows(ExceptionQueryError::class, "a failure inside an open transaction", $runBad);
        $this->check("a failure only undoes itself", $this->countRows() === 2);

        $withCallback = new Transaction($db, ['SELECT 1']);
        $withCallback->appendCallbacks(function () : void {});
        $runCallback = function () use ($withCallback) : void { $withCallback->execute(); };
        $this->checkThrows(ExceptionInvalidTransaction::class, "a callback inside an open transaction", $runCallback);
        $db->query('ROLLBACK');
        $this->check("rolling back the open transaction undoes it all", $this->countRows() === 0);

        $db->query('BEGIN');

        try
        {
            $db->query(self::BAD_QUERY);
        }
        catch (ExceptionQueryError)
        {
            // leaves the transaction failed.
        }

        $runInFailed = function () use ($db) : void { $db->runInTransaction('SELECT 1'); };
        $this->checkThrows(ExceptionInvalidTransaction::class, "running inside a failed transaction", $runInFailed);
        $db->query('ROLLBACK');
    }


    private function checkAfterCommit() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $other = ConnectionHandler::createNewConnection();
        $log = [];

        $first = function () use (&$log, $other, $db) : void {
            $count = pg_fetch_result($other->query('SELECT count(*) FROM "transaction_test"'), 0, 0);
            $log[] = "first saw {$count}, in transaction: " . var_export($db->isInTransaction(), true);
        };

        $second = function () use (&$log) : void { $log[] = 'second'; };
        $transaction = new Transaction($db, [$this->insert(1)]);
        $transaction->appendCallbacks($first);
        $transaction->appendCallbacks($second);
        $this->check("callbacks wait for it to run", $log === []);
        $transaction->execute();
        $this->check("they run in order, after the commit", $log === ['first saw 1, in transaction: false', 'second']);

        $log = [];
        $failing = new Transaction($db, [self::BAD_QUERY]);
        $failing->appendCallbacks($second);
        $runFailing = function () use ($failing) : void { $failing->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "a failing transaction", $runFailing);
        $this->check("they don't run if it fails", $log === []);

        $this->reset();
        $throws = function () : void { throw new \DomainException('callback failed'); };
        $transaction = new Transaction($db, [$this->insert(1)]);
        $transaction->appendCallbacks($throws);
        $transaction->appendCallbacks($second);

        try
        {
            $transaction->execute();
            $this->m_errorMessages[] = "A failing callback didn't throw.";
        }
        catch (ExceptionAfterCommitFailed $ex)
        {
            $this->check("it holds the original", $ex->getPrevious() instanceof \DomainException);
        }

        $this->check("the data stays committed", $this->countRows() === 1);
        $this->check("the other callbacks still run", $log === ['second']);
    }


    /**
     * A sequence isn't rolled back, so this query can fail with a real SQLSTATE for a set number of attempts.
     * @return void
     */
    private function checkRetries() : void
    {
        $db = ConnectionHandler::getDb();

        foreach (['40001', '40P01'] as $sqlState)
        {
            $this->reset();
            $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
            $transaction = new Transaction($db, [$this->insert(1), $this->failUntilAttempt(3, $sqlState)]);
            $count = 0;
            $transaction->appendCallbacks(function () use (&$count) : void { $count++; });
            $transaction->execute();
            $this->check("{$sqlState} is retried until it works", $this->fetchAttempts() === 3);
            $this->check("only one attempt's changes are kept", $this->countRows() === 1);
            $this->check("the callback only ran once", $count === 1);
        }

        $this->reset();
        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $tooFew = new Transaction($db, [$this->insert(1), $this->failUntilAttempt(9, '40001')], maxAttempts: 2);

        try
        {
            $tooFew->execute();
            $this->m_errorMessages[] = "Running out of attempts didn't throw.";
        }
        catch (ExceptionQueryError $ex)
        {
            $this->check("the error is marked as retryable", $ex->isRetryable());
            $this->check("it used every attempt", $this->fetchAttempts() === 2);
        }

        $this->reset();
        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $db->runInTransaction($this->insert(1), $this->failUntilAttempt(3, '40001'));
        $this->check("it retries up to 3 attempts by default", $this->fetchAttempts() === 3 && $this->countRows() === 1);

        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $beyondDefault = new Transaction($db, [$this->failUntilAttempt(4, '40001')]);
        $runBeyondDefault = function () use ($beyondDefault) : void { $beyondDefault->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "needing more than the default attempts", $runBeyondDefault);
        $this->check("the default stops at 3 attempts", $this->fetchAttempts() === 3);

        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $noRetries = new Transaction($db, [$this->failUntilAttempt(2, '40001')], maxAttempts: 1);
        $runNoRetries = function () use ($noRetries) : void { $noRetries->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "a clash with no retries", $runNoRetries);
        $this->check("maxAttempts of 1 means no retries", $this->fetchAttempts() === 1);

        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $permanent = new Transaction($db, [$this->failUntilAttempt(9, '23505')], maxAttempts: 5);
        $runPermanent = function () use ($permanent) : void { $permanent->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "a permanent error", $runPermanent);
        $this->check("a permanent error isn't retried", $this->fetchAttempts() === 1);

        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $db->query('BEGIN');
        $nested = new Transaction($db, [$this->failUntilAttempt(2, '40001')], maxAttempts: 5);
        $runNested = function () use ($nested) : void { $nested->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "a clash inside an open transaction", $runNested);
        $db->query('ROLLBACK');
        $this->check("it isn't retried inside an open transaction", $this->fetchAttempts() === 1);

        foreach ([0, -1] as $attempts)
        {
            $create = function () use ($db, $attempts) : void { new Transaction($db, [], maxAttempts: $attempts); };
            $this->checkThrows(ExceptionInvalidTransaction::class, "maxAttempts of {$attempts}", $create);
        }
    }


    private function checkCopies() : void
    {
        $db = ConnectionHandler::getDb();
        $original = new Transaction($db, ['SELECT 1']);
        $copy = clone $original;
        $copy->append('SELECT 2');
        $copy->appendCallbacks(function () : void {});
        $this->check("a copy doesn't change the original", count($original) === 1 && count($copy) === 2);
        $original->execute();
        $this->check("the original's callbacks don't include the copy's", $original->hasRun());

        $queries = new QueryCollection(new Query('SELECT 1'));
        $transaction = new Transaction($db, $queries);
        $queries->add('SELECT 2');
        $this->check("it keeps its own copy of the collection", count($transaction) === 1);
        $transaction->getQueries()->add('SELECT 3');
        $this->check("getQueries() returns a copy", count($transaction) === 1);
        $this->check("it isn't reusable by default", $transaction->isReusable() === false);

        $badAppend = function () use ($transaction) : void { $transaction->append('SELECT 4', 'COMMIT'); };
        $this->checkThrows(ExceptionInvalidQuery::class, "appending an invalid query", $badAppend);
        $this->check("a failed append adds nothing", count($transaction) === 1);
    }


    private function checkQueriesWithValues() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $insert = 'INSERT INTO "transaction_test" ("id") VALUES ($1) RETURNING "id"';
        $results = $db->runInTransaction(new Query($insert, [1]), $this->insert(2), new Query('SELECT $1::text', ["O'B"]));
        $this->check("each query with values has a result", count($results) === 3);
        $this->check("results stay in order", pg_fetch_result($results[0], 0, 0) === '1');
        $this->check("values aren't altered", pg_fetch_result($results[2], 0, 0) === "O'B");
        $this->check("mixed queries all ran", $this->countRows() === 2);

        $large = str_repeat("it's; a large value ", 100000);
        $result = $db->runInTransaction(new Query('SELECT $1::text', [$large]))[0];
        $this->check("a large value survives", pg_fetch_result($result, 0, 0) === $large);

        $this->reset();
        $bad = new Query('SELECT * FROM "no_such_table" WHERE "a" = $1', [1]);

        try
        {
            $db->runInTransaction(new Query($insert, [1]), $bad, $this->insert(3));
            $this->m_errorMessages[] = "A failing query with values didn't throw.";
        }
        catch (ExceptionQueryError $ex)
        {
            $this->check("it names the failed query with values", $ex->getQuery() === $bad->getSql());
            $this->check("it has the SQLSTATE with values", $ex->getSqlState() === '42P01');
        }

        $this->check("queries with values roll back", $this->countRows() === 0);
        $this->check("queries with values leave nothing open", $db->isInTransaction() === false);

        $db->query('BEGIN');
        $db->query($this->insert(1));
        $runBad = function () use ($db, $insert, $bad) : void { $db->runInTransaction(new Query($insert, [2]), $bad); };
        $this->checkThrows(ExceptionQueryError::class, "a failure with values in an open transaction", $runBad);
        $db->runInTransaction(new Query($insert, [3]));
        $db->query('COMMIT');
        $this->check("a savepoint with values only undoes itself", $this->countRows() === 2);

        $this->reset();
        $db->query('ALTER SEQUENCE "transaction_attempts" RESTART WITH 1');
        $retried = new Transaction($db, [new Query($insert, [1]), $this->failUntilAttempt(3, '40001')]);
        $retried->execute();
        $this->check("queries with values are retried", $this->fetchAttempts() === 3 && $this->countRows() === 1);
    }


    private function checkAcceptedForms() : void
    {
        $this->reset();
        $db = ConnectionHandler::getDb();
        $collection = new QueryCollection(new Query($this->insert(1)));
        $collection->add($this->insert(2));
        $collection[] = new Query($this->insert(3));
        $collection[] = $this->insert(4);
        (new Transaction($db, $collection))->execute();
        $this->check("a collection takes strings and Query objects", $this->countRows() === 4);

        $this->reset();
        $transaction = new Transaction($db, [$this->insert(1), new Query($this->insert(2))]);
        $transaction->append($this->insert(3), new Query($this->insert(4)));
        $transaction->execute();
        $this->check("an array and append() take both", $this->countRows() === 4);

        foreach ([[1], [null], [['SELECT 1']], [new \stdClass()]] as $badQueries)
        {
            $create = function () use ($db, $badQueries) : void { new Transaction($db, $badQueries); };
            $this->checkThrows(ExceptionInvalidTransaction::class, "an array holding a non-query", $create);
        }

        $invalidString = function () use ($db) : void { new Transaction($db, ['COMMIT']); };
        $this->checkThrows(ExceptionInvalidQuery::class, "an invalid string in the array", $invalidString);
    }


    private function checkCallbacksInConstructor() : void
    {
        $db = ConnectionHandler::getDb();
        $log = [];
        $first = function () use (&$log) : void { $log[] = 'first'; };
        $second = function () use (&$log) : void { $log[] = 'second'; };
        $third = function () use (&$log) : void { $log[] = 'third'; };

        $this->reset();
        $fromArray = new Transaction($db, [$this->insert(1)], [$first, $second]);
        $fromArray->appendCallbacks($third);
        $this->check("callbacks wait for it to run", $log === []);
        $fromArray->execute();
        $this->check("an array of callbacks runs in order", $log === ['first', 'second', 'third']);

        $log = [];
        $this->reset();
        $callbacks = new ClosureCollection($first);
        $fromCollection = new Transaction($db, [$this->insert(1)], $callbacks);
        $callbacks->add($second);
        $fromCollection->execute();
        $this->check("it keeps its own copy of the callbacks", $log === ['first']);

        $log = [];
        $failing = new Transaction($db, [self::BAD_QUERY], [$first]);
        $runFailing = function () use ($failing) : void { $failing->execute(); };
        $this->checkThrows(ExceptionQueryError::class, "a failing transaction with callbacks", $runFailing);
        $this->check("constructor callbacks don't run if it fails", $log === []);

        $this->reset();
        $reusable = new Transaction($db, ['SELECT 1'], [$first], isReusable: true);
        $reusable->execute();
        $reusable->execute();
        $this->check("a reusable transaction runs its callbacks each time", $log === ['first', 'first']);

        $log = [];
        $this->reset();
        $appended = new Transaction($db, [$this->insert(1)]);
        $appended->appendCallbacks($first, new ClosureCollection($second, $third), $first);
        $appended->appendCallbacks();
        $appended->execute();
        $this->check("appendCallbacks() takes closures and collections", $log === ['first', 'second', 'third', 'first']);

        foreach ([['strlen'], [1], [null], [new \stdClass()]] as $badCallbacks)
        {
            $create = function () use ($db, $badCallbacks) : void { new Transaction($db, [], $badCallbacks); };
            $this->checkThrows(ExceptionInvalidTransaction::class, "an array holding a non-closure", $create);
        }
    }


    private function failUntilAttempt(int $attempt, string $sqlState) : string
    {
        return <<<SQL
        DO $$ BEGIN
            IF nextval('transaction_attempts') < {$attempt} THEN
                RAISE EXCEPTION 'made-up failure' USING ERRCODE = '{$sqlState}';
            END IF;
        END $$
        SQL;
    }


    private function fetchAttempts() : int
    {
        $result = ConnectionHandler::getDb()->query('SELECT last_value FROM "transaction_attempts"');
        return (int)pg_fetch_result($result, 0, 0);
    }


    private function insert(int $id) : string
    {
        return "INSERT INTO \"transaction_test\" (\"id\") VALUES ({$id})";
    }


    private function reset() : void
    {
        ConnectionHandler::getDb()->query('TRUNCATE "transaction_test"');
    }


    private function countRows() : int
    {
        $result = ConnectionHandler::getDb()->query('SELECT count(*) AS "total" FROM "transaction_test"');
        return (int)pg_fetch_result($result, 0, 'total');
    }


    private function check(string $label, bool $passed) : void
    {
        if ($passed === false)
        {
            $this->m_errorMessages[] = "Expected: {$label}";
        }
    }


    private function checkThrows(string $exceptionClass, string $label, \Closure $action) : void
    {
        try
        {
            $action();
            $this->m_errorMessages[] = "Expected {$exceptionClass} from: {$label}";
        }
        catch (\Throwable $ex)
        {
            if (!($ex instanceof $exceptionClass))
            {
                $this->m_errorMessages[] = "Expected {$exceptionClass} from {$label}, got " . get_class($ex) . ": {$ex->getMessage()}";
            }
        }
    }
}
