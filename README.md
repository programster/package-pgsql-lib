PgSQL Lib
==========

[![Tests](https://github.com/programster/package-pgsql-lib/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-pgsql-lib/actions/workflows/tests.yml)

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Escaping Values](#escaping-values)
- [Queries With Values](#queries-with-values)
- [Transactions](#transactions)
- [Using a Schema](#using-a-schema)
- [UUIDs](#uuids)
- [Errors](#errors)
- [Asynchronous Connections](#asynchronous-connections)
- [Testing](#testing)
- [Upgrading](#upgrading)
- [Licence](#licence)
- [Full documentation](docs/README.md)

A library for helping interface with PostgreSQL.


## Requirements
* PHP 8.2 or later
* The `pgsql` extension


## Installation
```bash
composer require programster/pgsql-lib
```


## Usage
Create a connection with `PgSqlConnection::create()`, or wrap an existing `PgSql\Connection` from `pg_connect()`.
`create()`'s `forceNew` parameter is required:

* `true` always opens a new, independent connection. Use this unless you have a reason not to.
* `false` lets PHP return a connection that the script already has open with the same details. Both
  `PgSqlConnection` objects then share one database session, including its transactions and session settings, and
  closing one closes the other. To share a connection deliberately, pass the same `PgSqlConnection` object around
  instead.


```php
use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgSqlConnection;

$db = PgSqlConnection::create('localhost', 'myDbName', 'myUsername', 'myPassword', forceNew: true);
# or: $db = new PgSqlConnection(pg_connect($connectionString));

$insertQuery = $db->generateBatchInsertQuery('user', [
    ['id' => '952036f3-3486-4630-9889-9f46b50a54e5', 'name' => 'bob', 'email' => 'bob@example.com'],
]);

$db->query($insertQuery);

$selectQuery = $db->generateSelectWhereQuery('user', ['email' => 'bob@example.com'], Conjunction::AND);
$result = $db->query($selectQuery);
```

`query()` returns a `PgSql\Result`, and throws an `ExceptionQueryError` if the query fails. The exception holds
PostgreSQL's error message and the query that failed. The warning PHP's `pg_query()` raises on failure is captured
rather than passed to your error handler, so a handler that turns warnings into exceptions won't throw first:

```php
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;

try
{
    $db->query($query);
}
catch (ExceptionQueryError $ex)
{
    error_log("{$ex->getErrorMessage()} in query: {$ex->getQuery()}");
}
```

With `generateBatchInsertQuery()`, every row must have the same columns, otherwise it throws an
`ExceptionMismatchedRows`. If some rows leave out columns so that the database fills in their default values, list
those columns in the `columnsWithDefaults` parameter. Rows that leave them out then get `DEFAULT`. An explicit `null`
still inserts `NULL`.

```php
$insertQuery = $db->generateBatchInsertQuery(
    'user',
    [
        ['id' => '952036f3-3486-4630-9889-9f46b50a54e5', 'name' => 'alice', 'status' => 'admin'],
        ['id' => '952036f3-3559-4396-9352-81f7dc5a978a', 'name' => 'bob'], # status gets its default
    ],
    columnsWithDefaults: ['status']
);
```

`generateDeleteWhereQuery()` throws an `ExceptionNoData` if it's given no where pairs, rather than generating a
query that deletes every row in the table. If you really want to empty a table, write that query explicitly.
`generateQueryPairs()` also throws an `ExceptionNoData` if it's given no pairs.

In where pairs, a value can be a single value, or a list of values to generate `IN()`. A `null` value generates
`IS NULL`, including when it's one of the values in a list:

```php
# WHERE ("nickname" IN('ali','caz') OR "nickname" IS NULL) AND "status" = 'active'
$query = $db->generateSelectWhereQuery(
    'user',
    ['nickname' => ['ali', 'caz', null], 'status' => 'active'],
    Conjunction::AND
);
```

`PgsqlLib` provides the same functionality as static methods that take the `PgSql\Connection` as their first
parameter, for if you'd rather not use the wrapper.


## Escaping Values
The generators escape values for you. To build your own queries, use `escapeValue()` or `escapeValues()`, which
return each value in the form to put straight into the query:

| Value | Returned as |
|---|---|
| String | A quoted, escaped literal, e.g. `'o''brien'` |
| Int | The int, unchanged |
| Float | A string with full precision, e.g. `0.30000000000000004`. PHP's own float to string conversion only keeps 14 significant digits. `INF`, `-INF` and `NAN` become `'Infinity'::float8`, `'-Infinity'::float8` and `'NaN'::float8`. |
| Bool | `TRUE` or `FALSE` |
| Null | `NULL` |
| Backed enum | Its backing value, escaped as above |

Any other type throws an `ExceptionUnexpectedValueType`.

Some things to know about the special float values:
* `Infinity`, `-Infinity` and `NaN` can be stored in `real` and `double precision` columns. `numeric` columns only
  accept `NaN` before PostgreSQL 14, and all three from PostgreSQL 14. `integer` columns reject all of them.
* PostgreSQL treats `NaN` as equal to itself, unlike PHP, so a where clause such as `['value' => NAN]` matches rows
  where the value is `NaN`. PostgreSQL also sorts `NaN` above every other value, including `Infinity`.
* When reading these values back, PHP's `(float)` cast doesn't understand PostgreSQL's names for them, so
  `(float)'Infinity'` gives `0`. Map `'Infinity'`, `'-Infinity'` and `'NaN'` to `INF`, `-INF` and `NAN` yourself.

```php
$query = "SELECT * FROM {$db->escapeIdentifier('user')} WHERE {$db->escapeIdentifier('id')} > {$db->escapeValue($id)}";
```


## Queries With Values
`query()` runs a query as it's given, such as one built with the escaping methods above. Optionally, give it the
values as a second argument instead, and they're sent separately from the query (PostgreSQL's `pg_query_params`), as
`$1`, `$2` and so on, so they're never part of the SQL and need no escaping:

```php
$result = $db->query('SELECT * FROM "user" WHERE "email" = $1 AND "status" = $2', [$email, 'active']);
```

The values must be a list, in the order of their placeholders. Strings are sent as they are, nulls as SQL `NULL`,
bools as `true` or `false` (`pg_query_params` on its own would send `false` as an empty string), floats with their
full precision (or as `Infinity`, `-Infinity` and `NaN`), and backed enums as their backing values. Anything else, or
values keyed by name, throws an `ExceptionUnexpectedValueType`, before anything is sent.

Without values, the query is sent as it is (`pg_query`), as before, so it can hold several statements separated by
semicolons. A query with values can only hold one, as PostgreSQL doesn't allow more.


## Transactions
Run several queries so that either they all take effect, or none do:

```php
$db->runInTransaction(
    'UPDATE "account" SET "balance" = "balance" - 100 WHERE "id" = 1',
    'UPDATE "account" SET "balance" = "balance" + 100 WHERE "id" = 2'
);
```

To build one up, pass it around and run it later, use a `Transaction`:

```php
use Programster\PgsqlLib\Transaction;

$transaction = new Transaction($db, ['UPDATE "account" SET "balance" = "balance" - 100 WHERE "id" = 1']);
$transaction->append('UPDATE "account" SET "balance" = "balance" + 100 WHERE "id" = 2');
$transaction->execute(); # commit(), run() and $transaction() do the same
```

Anywhere that takes a query takes either a string or a `Query`. Use a `Query` to send values separately from the SQL,
so they need no escaping, and a large value isn't copied into the query:

```php
use Programster\PgsqlLib\Query;

$db->runInTransaction(
    new Query('UPDATE "account" SET "name" = $1 WHERE "id" = $2', ['Bob', 1]),
    'UPDATE "account" SET "balance" = 0 WHERE "id" = 2'
);
```

It can also run after-commit callbacks, and retry on serialization failures and deadlocks. See
[Transactions](docs/transactions.md).


## Using a Schema
Wherever a method takes a table, you can pass either a table name as a string, or a `Table`. A string, or a `Table`
without a schema, is left unqualified, so PostgreSQL finds the table using the connection's `search_path`. That is
`public` by default, but it can be changed per role, database or session. To target a table in a specific schema,
give the `Table` a schema:

```php
use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\Table;

# SELECT * FROM "app"."user" WHERE "email" = 'bob@example.com'
$query = $db->generateSelectWhereQuery(new Table('user', 'app'), ['email' => 'bob@example.com'], Conjunction::AND);
```

Don't pass a dotted name such as `app.user` as the table name. It gets escaped as a single identifier, so PostgreSQL
looks for a table literally called `app.user`. Pass the table and schema separately.

To build your own queries, use `escapeTableName()`, which escapes the schema and table separately:

```php
$db->escapeTableName('user');                    # "user"
$db->escapeTableName(new Table('user'));         # "user"
$db->escapeTableName(new Table('user', 'app'));  # "app"."user"
```

The same methods are available statically on `PgsqlLib`, taking the `PgSql\Connection` as the first parameter.


## UUIDs
`PgsqlLib::generateUuid()` generates a UUID ([RFC 9562](https://www.rfc-editor.org/rfc/rfc9562)) of the version you
give it, in lowercase. PostgreSQL's own `uuidv7()` only arrived in PostgreSQL 18, whereas this works with any version,
and lets you know a row's ID before inserting it.

- **`UuidVersion::V7`** starts with the current time, to a 4096th of a millisecond, and ends with 62 random bits, so
  UUIDs generated later sort after those generated earlier, both as strings and in a `uuid` column. That makes them
  good primary keys, as each new row goes at the end of the index, rather than somewhere random in it. Within a
  process, each is always greater than the one before it, even if two are generated within the same 4096th of a
  millisecond, or the clock goes backwards.
- **`UuidVersion::V4`** is 122 random bits, so use it where a UUID mustn't give away when it was made, or the order
  things were made in.
- **`UuidVersion::V1`** is for systems that expect one. It holds the time too, to a 10th of a microsecond, but doesn't
  sort by it. It ends with a random node, with its multicast bit set (as RFC 9562 allows), rather than the machine's
  MAC address, so it doesn't give away which machine made it. The node is chosen afresh in each process, including
  after a fork.

`PgsqlLib::isValidUuid()` checks whether a string is a UUID of any version, in its usual form: 32 hex digits, in either
case, in groups of 8, 4, 4, 4 and 12, separated by hyphens. Unlike PostgreSQL's `uuid` type, it doesn't accept braces,
missing hyphens, spaces or a trailing newline, so check an ID with it before using it in a query, rather than having
PostgreSQL reject the query, or tidy up an ID that doesn't then match the one you were given.

```php
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;

$id = PgsqlLib::generateUuid(UuidVersion::V7); # e.g. "019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37"
$db->query('INSERT INTO "user" ("id", "name") VALUES ($1, $2)', [$id, 'bob']);

PgsqlLib::isValidUuid($id);                                       # true
PgsqlLib::isValidUuid("{019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37}");  # false
```


## Errors
Every exception the library throws extends `Programster\PgsqlLib\Exceptions\AbstractException`, so you can catch
them all in one place:

| Exception | Thrown when |
|---|---|
| `ExceptionConnectionError` | Connecting fails. The message includes PostgreSQL's reason. |
| `ExceptionQueryError` | A query fails. |
| `ExceptionNoData` | A generator is given nothing to work with, such as no rows or no where pairs. |
| `ExceptionMismatchedRows` | Batch insert rows have different columns, and the missing ones aren't in `columnsWithDefaults`. |
| `ExceptionUnexpectedValueType` | A value can't be escaped. |
| `ExceptionInvalidTableName` | A `Table` is given an empty table or schema name. |
| `ExceptionInvalidQuery` | A `Query` is given SQL it can't use. |
| `ExceptionInvalidTransaction` | A transaction is used in a way that can't work, such as running it twice. |
| `ExceptionAfterCommitFailed` | An after-commit callback failed. The transaction was committed. |


## Asynchronous Connections
With `useAsync: true`, `create()` returns straight away, before the connection is established. Poll it until it's
ready before running any queries:

```php
$db = PgSqlConnection::create('localhost', 'myDbName', 'myUsername', 'myPassword', forceNew: true, useAsync: true);

# ... do other work ...

do
{
    $status = pg_connect_poll($db->getResource());
} while ($status !== PGSQL_POLLING_OK && $status !== PGSQL_POLLING_FAILED);
```


## Testing
The tests need Docker, or a throwaway PostgreSQL database. To run them against every supported version:

```bash
composer install
testing/run-all-versions.sh
```

See [Testing](docs/testing.md).


## Upgrading
See [Upgrading](docs/upgrading.md) for what changed in each release.


## Licence
MIT. See [LICENSE](LICENSE).
