<?php

/*
 * A list of SQL queries to run together: either they all take effect, or none do. It can be passed around and added
 * to, and nothing is sent to the database until it's executed.
 *
 * It can only be executed once, whether or not that succeeds, unless it was created with $isReusable set to true. If
 * the connection is lost during a commit, there's no way to know whether the commit happened, so running it again could
 * apply everything twice.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use PgSql\Result;
use Programster\PgsqlLib\Exceptions\ExceptionAfterCommitFailed;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;


final class Transaction implements \Countable
{
    private const SAVEPOINT = 'pgsql_lib_transaction';
    private const MAX_RETRY_DELAY_MILLISECONDS = 1000;
    private const BASE_RETRY_DELAY_MILLISECONDS = 50;

    // the new line ends any comment at the end of a query, which would otherwise swallow the semicolon.
    private const STATEMENT_SEPARATOR = "\n;\n";

    private readonly PgSqlConnection $m_db;
    private readonly int $m_maxAttempts;
    private readonly bool $m_isReusable;
    private QueryCollection $m_queries;
    private ClosureCollection $m_afterCommitCallbacks;
    private bool $m_hasRun = false;


    /**
     * Create a transaction.
     *
     * @param PgSqlConnection $db - the connection to run it on.
     *
     * @param QueryCollection|array<string|Query> $queries - the queries to start with: a collection, of which the
     * transaction keeps its own copy, or an array of SQL strings (and/or Query objects), which it builds one from.
     *
     * @param ClosureCollection|array<\Closure> $afterCommitCallbacks - what to do once it has committed, such as
     * sending an email, in order. They don't run if it fails, and all of them run, even if one throws. Default: none.
     *
     * @param int $maxAttempts - how many times to attempt it in total, including the first, if it fails with a
     * serialization failure or a deadlock. PostgreSQL has rolled it back by then, and expects it to be run again.
     * Nothing else is retried, including a lost connection. Use 1 for no retries.
     *
     * @param bool $isReusable - whether it can be executed more than once. Defaults to false, as running the same
     * changes twice is usually a mistake.
     *
     * @throws ExceptionInvalidQuery - if a string in the array can't be used as a query (see Query).
     * @throws ExceptionInvalidTransaction - if $queries holds anything but strings and Query objects, or
     * $afterCommitCallbacks holds anything but closures, or $maxAttempts is below 1.
     */
    public function __construct(
        PgSqlConnection $db,
        QueryCollection|array $queries,
        ClosureCollection|array $afterCommitCallbacks = [],
        int $maxAttempts = 3,
        bool $isReusable = false
    )
    {
        if ($maxAttempts < 1)
        {
            throw new ExceptionInvalidTransaction("A transaction needs at least 1 attempt, not {$maxAttempts}.");
        }

        $isCallbackArray = is_array($afterCommitCallbacks);
        $this->m_db = $db;
        $this->m_queries = is_array($queries) ? self::createQueryCollection($queries) : clone $queries;

        $this->m_afterCommitCallbacks = $isCallbackArray
            ? self::createClosureCollection($afterCommitCallbacks)
            : clone $afterCommitCallbacks;

        $this->m_maxAttempts = $maxAttempts;
        $this->m_isReusable = $isReusable;
    }


    public function __clone()
    {
        $this->m_queries = clone $this->m_queries;
        $this->m_afterCommitCallbacks = clone $this->m_afterCommitCallbacks;
    }


    /**
     * Add queries to the end of the transaction. If any of them can't be used, none are added.
     *
     * @param Query|string ...$queries - the queries, in the order to run them.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if it can only run once, and already has.
     * @throws ExceptionInvalidQuery - if a string can't be used as a query (see Query).
     */
    public function append(Query|string ...$queries) : void
    {
        $this->assertCanRun();
        $this->m_queries = $this->m_queries->merge(self::createQueryCollection($queries));
    }


    /**
     * Add things to do once the transaction has committed, such as sending an email. They don't run if the transaction
     * fails. They run in the order they were added, and all of them run, even if one throws.
     *
     * @param \Closure|ClosureCollection ...$callbacks - the callbacks, and/or collections of them.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if it can only run once, and already has.
     */
    public function appendCallbacks(\Closure|ClosureCollection ...$callbacks) : void
    {
        $this->assertCanRun();

        foreach ($callbacks as $callback)
        {
            $closures = ($callback instanceof ClosureCollection) ? $callback->toArray() : [$callback];

            foreach ($closures as $closure)
            {
                $this->m_afterCommitCallbacks->add($closure);
            }
        }
    }


    /**
     * Run every query, in order, in one transaction, then the after-commit callbacks. If a transaction is already open
     * on the connection, the queries run inside a savepoint within it, so a failure only undoes these queries.
     *
     * If none of the queries have params, they're all sent in a single round trip. Otherwise each is sent on its own,
     * as PostgreSQL only accepts one statement at a time with params.
     *
     * @return QueryResultCollection - each query's result, in order.
     *
     * @throws ExceptionInvalidTransaction - if it can only run once and already has, the open transaction has already
     * failed, or it has after-commit callbacks and a transaction is already open, as it can't know when that commits.
     *
     * @throws ExceptionQueryError - if a query or the commit failed, after rolling back.
     * @throws ExceptionAfterCommitFailed - if a callback failed. The transaction WAS committed.
     */
    public function execute() : QueryResultCollection
    {
        $this->assertCanRun();
        $this->assertCanStart();

        // even if this fails, so that it can't be run again by mistake (see the top of this class).
        $this->m_hasRun = true;
        $isNested = $this->m_db->isInTransaction();
        $maxAttempts = $isNested ? 1 : $this->m_maxAttempts;
        $failedAttempts = 0;
        $isFinished = false;

        while ($isFinished === false)
        {
            try
            {
                $results = $this->hasParams() ? $this->runEachQuery($isNested) : $this->runAsBatch($isNested);
                $isFinished = true;
            }
            catch (ExceptionQueryError $exception)
            {
                $failedAttempts++;

                if ($exception->isRetryable() === false || $failedAttempts >= $maxAttempts)
                {
                    throw $exception;
                }

                usleep(self::calculateRetryDelayMicroseconds($failedAttempts));
            }
        }

        $this->runAfterCommitCallbacks();
        return $results;
    }


    /**
     * An alias for execute().
     * @return QueryResultCollection
     */
    public function commit() : QueryResultCollection
    {
        return $this->execute();
    }


    /**
     * An alias for execute().
     * @return QueryResultCollection
     */
    public function run() : QueryResultCollection
    {
        return $this->execute();
    }


    /**
     * An alias for execute(), so that $transaction() runs it.
     * @return QueryResultCollection
     */
    public function __invoke() : QueryResultCollection
    {
        return $this->execute();
    }


    public function count() : int
    {
        return count($this->m_queries);
    }


    public function hasRun() : bool
    {
        return $this->m_hasRun;
    }


    public function isReusable() : bool
    {
        return $this->m_isReusable;
    }


    /**
     * Build a collection from SQL strings and Query objects.
     *
     * @param array<mixed> $queries - the strings and Query objects.
     *
     * @return QueryCollection
     *
     * @throws ExceptionInvalidQuery - if a string can't be used as a query (see Query).
     * @throws ExceptionInvalidTransaction - if it holds anything else.
     */
    private static function createQueryCollection(array $queries) : QueryCollection
    {
        $built = [];

        foreach ($queries as $query)
        {
            if ($query instanceof Query)
            {
                $built[] = $query;
            }
            elseif (is_string($query))
            {
                $built[] = new Query($query);
            }
            else
            {
                $type = get_debug_type($query);
                throw new ExceptionInvalidTransaction("A transaction's queries must be strings or Query, not {$type}.");
            }
        }

        return new QueryCollection(...$built);
    }


    /**
     * Build a collection from an array of closures.
     * @param array<mixed> $callbacks - the closures.
     * @return ClosureCollection
     * @throws ExceptionInvalidTransaction - if it holds anything else.
     */
    private static function createClosureCollection(array $callbacks) : ClosureCollection
    {
        $closures = [];

        foreach ($callbacks as $callback)
        {
            if (!($callback instanceof \Closure))
            {
                $type = get_debug_type($callback);
                throw new ExceptionInvalidTransaction("After-commit callbacks must be closures, not {$type}.");
            }

            $closures[] = $callback;
        }

        return new ClosureCollection(...$closures);
    }


    /**
     * Work out how long to wait before another attempt: a random time up to a limit that doubles with each failure.
     * @param int $failedAttempts - how many attempts have failed so far.
     * @return int - the time to wait, in microseconds.
     */
    private static function calculateRetryDelayMicroseconds(int $failedAttempts) : int
    {
        $limit = self::BASE_RETRY_DELAY_MILLISECONDS * (2 ** min($failedAttempts - 1, 10));
        return random_int(0, min($limit, self::MAX_RETRY_DELAY_MILLISECONDS) * 1000);
    }


    private function hasParams() : bool
    {
        $hasParams = false;

        foreach ($this->m_queries as $query)
        {
            $hasParams = ($hasParams || $query->hasParams());
        }

        return $hasParams;
    }


    /**
     * Send everything as one query, in a single round trip, and read back every statement's result.
     * @param bool $isNested - whether a transaction was already open.
     * @return QueryResultCollection - each query's result, in order.
     * @throws ExceptionQueryError - if anything failed, after rolling back.
     */
    private function runAsBatch(bool $isNested) : QueryResultCollection
    {
        $queries = array_values($this->m_queries->toArray());
        $statements = [$this->getOpeningStatement($isNested)];

        foreach ($queries as $query)
        {
            $statements[] = $query->getSql();
        }

        $statements[] = $this->getClosingStatement($isNested);
        $sql = implode(self::STATEMENT_SEPARATOR, $statements);
        $this->send($sql);
        $results = $this->readResults($error);

        // no results at all, not even an error, means that the connection was lost.
        if ($error !== null || count($results) === 0)
        {
            $this->rollBack($isNested);
            $failedQuery = $queries[count($results) - 1] ?? null;
            throw $this->createQueryError($error, ($failedQuery === null) ? $sql : $failedQuery->getSql());
        }

        return new QueryResultCollection(...array_slice($results->toArray(), 1, count($queries)));
    }


    /**
     * Send each statement on its own, as is needed when any of them have params.
     * @param bool $isNested - whether a transaction was already open.
     * @return QueryResultCollection - each query's result, in order.
     * @throws ExceptionQueryError - if anything failed, after rolling back.
     */
    private function runEachQuery(bool $isNested) : QueryResultCollection
    {
        $results = [];

        try
        {
            $this->runStatement($this->getOpeningStatement($isNested));

            foreach ($this->m_queries as $query)
            {
                $results[] = $this->runStatement($query->getSql(), $query->getParams());
            }

            $this->runStatement($this->getClosingStatement($isNested));
        }
        catch (ExceptionQueryError $exception)
        {
            $this->rollBack($isNested);
            throw $exception;
        }

        return new QueryResultCollection(...$results);
    }


    /**
     * Run one statement.
     * @param string $sql - the statement.
     * @param list<string|null> $params - the values for its placeholders, as text.
     * @return Result
     * @throws ExceptionQueryError - if it failed.
     */
    private function runStatement(string $sql, array $params = []) : Result
    {
        $this->send($sql, $params);
        $results = $this->readResults($error);

        if ($error !== null || count($results) === 0)
        {
            throw $this->createQueryError($error, $sql);
        }

        return $results->first();
    }


    private function getOpeningStatement(bool $isNested) : string
    {
        return $isNested ? 'SAVEPOINT ' . self::SAVEPOINT : 'BEGIN';
    }


    private function getClosingStatement(bool $isNested) : string
    {
        return $isNested ? 'RELEASE SAVEPOINT ' . self::SAVEPOINT : 'COMMIT';
    }


    /**
     * Send some SQL, keeping PHP's warning from the application's error handler, as failure is reported by throwing.
     * @param string $sql - the SQL.
     * @param list<string|null> $params - the values for its placeholders, as text.
     * @return void
     * @throws ExceptionQueryError - if it couldn't be sent.
     */
    private function send(string $sql, array $params = []) : void
    {
        $connection = $this->m_db->getResource();
        $ignoreWarning = function () : bool { return true; };
        set_error_handler($ignoreWarning);

        try
        {
            $status = (count($params) === 0)
                ? pg_send_query($connection, $sql)
                : pg_send_query_params($connection, $sql, $params);

            // 0 means that it's still being sent, which only happens on a connection in non-blocking mode.
            while ($status === 0)
            {
                $status = pg_flush($connection);
            }
        }
        finally
        {
            restore_error_handler();
        }

        if ($status === false)
        {
            throw new ExceptionQueryError(pg_last_error($connection), $sql);
        }
    }


    /**
     * Read every result of what was sent, as they all have to be read before the connection can be used again.
     * @param Result|null $error - set to the failed result, or null if nothing failed.
     * @return QueryResultCollection - the results of the statements that succeeded.
     */
    private function readResults(?Result &$error) : QueryResultCollection
    {
        $connection = $this->m_db->getResource();
        $results = [];
        $error = null;

        while (($result = pg_get_result($connection)) !== false)
        {
            $status = pg_result_status($result);

            if ($status === PGSQL_FATAL_ERROR || $status === PGSQL_BAD_RESPONSE)
            {
                $error = $result;
            }
            else
            {
                $results[] = $result;
            }
        }

        return new QueryResultCollection(...$results);
    }


    /**
     * Create the error for a failed statement.
     * @param Result|null $error - the failed result, or null if the connection was lost.
     * @param string $failedSql - the statement that failed.
     * @return ExceptionQueryError
     */
    private function createQueryError(?Result $error, string $failedSql) : ExceptionQueryError
    {
        $message = ($error === null) ? pg_last_error($this->m_db->getResource()) : (string)pg_result_error($error);
        $state = ($error === null) ? '' : (string)pg_result_error_field($error, PGSQL_DIAG_SQLSTATE);
        return new ExceptionQueryError($message, $failedSql, $state);
    }


    /**
     * Undo the attempt, keeping any error from doing so quiet, as the original error is the useful one.
     * @param bool $isNested - whether it's in a savepoint.
     * @return void
     */
    private function rollBack(bool $isNested) : void
    {
        $savepoint = self::SAVEPOINT;
        $undoSavepoint = "ROLLBACK TO SAVEPOINT {$savepoint}; RELEASE SAVEPOINT {$savepoint}";

        if ($this->m_db->isInTransaction())
        {
            try
            {
                $this->m_db->query($isNested ? $undoSavepoint : 'ROLLBACK');
            }
            catch (ExceptionQueryError)
            {
                // the connection may have gone, which also ends the transaction.
            }
        }
    }


    /**
     * Run the after-commit callbacks. They all run, even if one fails, as the transaction has been committed.
     * @return void
     * @throws ExceptionAfterCommitFailed - if any of them failed, holding the first failure as its previous exception.
     */
    private function runAfterCommitCallbacks() : void
    {
        $failures = [];

        foreach ($this->m_afterCommitCallbacks as $callback)
        {
            try
            {
                $callback();
            }
            catch (\Throwable $exception)
            {
                $failures[] = $exception;
            }
        }

        if (count($failures) > 0)
        {
            throw new ExceptionAfterCommitFailed($failures[0], count($failures));
        }
    }


    /**
     * Check that the transaction can still be changed and run.
     * @return void
     * @throws ExceptionInvalidTransaction - if it can only run once, and already has.
     */
    private function assertCanRun() : void
    {
        if ($this->m_hasRun && $this->m_isReusable === false)
        {
            throw new ExceptionInvalidTransaction(
                "This transaction has already been executed, so it can't be changed or run again. Create a new one, or"
                . " create it with isReusable set to true for one that can run many times."
            );
        }
    }


    /**
     * Check that the connection is in a state that this can start in.
     * @return void
     * @throws ExceptionInvalidTransaction - if the open transaction has failed, or callbacks couldn't be honoured.
     */
    private function assertCanStart() : void
    {
        if (pg_transaction_status($this->m_db->getResource()) === PGSQL_TRANSACTION_INERROR)
        {
            throw new ExceptionInvalidTransaction(
                "The transaction that's open has already failed, so nothing more can run in it until it's rolled back."
            );
        }

        if ($this->m_db->isInTransaction() && count($this->m_afterCommitCallbacks) > 0)
        {
            throw new ExceptionInvalidTransaction(
                "After-commit callbacks can't be used inside a transaction that's already open, as there's no way to"
                . " know when it commits."
            );
        }
    }


    # Accessors
    public function getQueries() : QueryCollection { return clone $this->m_queries; }
}
