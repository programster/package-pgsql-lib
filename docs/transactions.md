# Transactions

- [Running queries together](#running-queries-together)
- [Transaction objects](#transaction-objects)
- [What's checked](#whats-checked)
- [Values](#values)
- [Isolation levels and deferred constraints](#isolation-levels-and-deferred-constraints)
- [After-commit callbacks](#after-commit-callbacks)
- [Retries](#retries)
- [Inside an open transaction](#inside-an-open-transaction)
- [Extending it](#extending-it)
- [Errors](#errors)

## Running queries together

`runInTransaction()` runs its queries in one transaction, so either they all take effect, or none do. It returns one
`PgSql\Result` per query, in order.

```php
$results = $db->runInTransaction(
    'UPDATE "account" SET "balance" = "balance" - 100 WHERE "id" = 1',
    'UPDATE "account" SET "balance" = "balance" + 100 WHERE "id" = 2',
    'INSERT INTO "transfer" ("from_id", "to_id") VALUES (1, 2)'
);
```

## Transaction objects

A `Transaction` takes an array of queries, or a `QueryCollection`. It can be built up and passed around, and nothing
reaches the database until it's executed. Anywhere that takes a query takes either a SQL string or a `Query`.

```php
use Programster\PgsqlLib\Transaction;

$transaction = new Transaction($db, ['UPDATE "account" SET "balance" = "balance" - 100 WHERE "id" = 1']);
$transaction->append('UPDATE "account" SET "balance" = "balance" + 100 WHERE "id" = 2');
$results = $transaction->execute();
```

`execute()`, `commit()`, `run()` and `$transaction()` all do the same thing.

A transaction can only run **once**, even if it failed. If the connection is lost during a commit, there's no way to
know whether it happened, so running it again could apply everything twice. For one you deliberately want to run many
times, set `isReusable`:

```php
use Programster\PgsqlLib\Transaction;

$addInterest = new Transaction($db, ['UPDATE "account" SET "balance" = "balance" + 1'], isReusable: true);
$addInterest->execute();
$addInterest->execute();
```

## What's checked

Each query is checked when it becomes a `Query`, so a mistake is reported on the line that made it. It's rejected if it:

- Is empty, holds more than one statement, or has an unterminated string or comment.
- Controls the transaction itself, such as `BEGIN`, `COMMIT`, `ROLLBACK` or `SAVEPOINT`.
- Can't run in a transaction, such as `VACUUM` or `CREATE INDEX CONCURRENTLY`.
- Uses `COPY` to or from the client, or has `$1` placeholders that don't match its values.

A `;` or `COMMIT` inside a string, quoted identifier, dollar quote or comment is fine. This isn't a full parser, so
anything else wrong with the SQL is reported by PostgreSQL when it runs, and the whole transaction is rolled back.

## Values

Use a `Query` to send values separately from the SQL. They need no escaping, and a large value isn't copied into the
query, so it uses much less memory.

```php
use Programster\PgsqlLib\Query;

$name = "O'Brien";

$db->runInTransaction(
    new Query('SELECT $1::text', [$name]),
    new Query('UPDATE "account" SET "balance" = $1 WHERE "id" = $2', [500, 1]),
    'UPDATE "account" SET "balance" = 0 WHERE "id" = 2'
);
```

If none of the queries have values, they're all sent in a single round trip. Otherwise each is sent on its own, as
PostgreSQL only accepts one statement at a time with values.

## Isolation levels and deferred constraints

These are ordinary statements, so put them first:

```php
$db->runInTransaction(
    'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
    'SET CONSTRAINTS ALL DEFERRED',
    'UPDATE "account" SET "balance" = "balance" + 1'
);
```

## After-commit callbacks

Some things should only happen once the data is really saved, such as sending an email.

```php
use Programster\PgsqlLib\Transaction;

$sendReceipt = function () : void {
    // send the email
};

$transaction = new Transaction($db, ['UPDATE "account" SET "balance" = "balance" + 1'], [$sendReceipt]);
$transaction->execute();
```

Pass them as an array or a `ClosureCollection`, or add more later with `appendCallbacks()`, which takes any number of
closures and collections. Callbacks run in the order they were added, and don't run if the transaction fails. If one
throws, the others still run, and you then get an `ExceptionAfterCommitFailed`, with the first failure as its previous
exception. **The transaction was committed**, so don't run it again.

## Retries

PostgreSQL can reject a transaction that clashed with another, and expects it to be run again. A transaction is
attempted up to **3 times** by default, but only for serialization failures and deadlocks, which PostgreSQL has already
rolled back. Any other error fails straight away, and a lost connection is never retried, as the commit may have worked.

```php
use Programster\PgsqlLib\Transaction;

$queries = [
    'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
    'UPDATE "account" SET "balance" = "balance" + 1',
];

$transaction = new Transaction($db, $queries, maxAttempts: 5);
$transaction->execute();
```

`maxAttempts` counts the first attempt, so use `1` for no retries. It waits a random time between attempts, up to a
limit that doubles each time, to a maximum of a second. After-commit callbacks only run once, after the attempt that
succeeded.

## Inside an open transaction

If a transaction is already open on the connection, the queries run inside a savepoint. A failure only undoes them, and
nothing is final until the open transaction commits. Retries don't apply there, as PostgreSQL needs the whole
transaction to be run again, and after-commit callbacks are refused, as there's no way to know when it commits.

## Extending it

`Transaction` can be extended, to add to what it runs and act on the results, with two methods:

- `prepareQueries()` returns the queries to run. It's called once, when the transaction is executed.
- `afterSuccess($results)` is called once it has succeeded, before the after-commit callbacks, and returns what
  `execute()` should return.

```php
use Programster\PgsqlLib\QueryCollection;
use Programster\PgsqlLib\QueryResultCollection;
use Programster\PgsqlLib\Transaction;

$audited = new class($db, ['UPDATE "account" SET "balance" = "balance" + 1']) extends Transaction {
    protected function prepareQueries() : QueryCollection
    {
        $queries = clone parent::prepareQueries();
        $queries->add('INSERT INTO "transfer" ("from_id", "to_id") VALUES (1, 2)');
        return $queries;
    }

    protected function afterSuccess(QueryResultCollection $results) : QueryResultCollection
    {
        return new QueryResultCollection($results[0]);
    }
};

$audited->execute();
```

## Errors

| Exception | Thrown when |
|---|---|
| `ExceptionInvalidQuery` | A `Query` is given SQL it can't use. |
| `ExceptionInvalidTransaction` | The transaction is used in a way that can't work, such as running it twice. |
| `ExceptionQueryError` | A query or the commit failed, and it was rolled back. `getQuery()` holds the failed query. |
| `ExceptionAfterCommitFailed` | An after-commit callback failed. The transaction was committed. |

`ExceptionQueryError` now also has `getSqlState()`, for PostgreSQL's error code, and `isRetryable()`, for whether it was
a serialization failure or deadlock.
