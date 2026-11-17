PgSQL Lib
==========

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
The tests need a PostgreSQL database. **They run migrations against it and empty its tables, so use a throwaway
database.** Copy `testing/settings.php.tmpl` to `testing/settings.php`, fill in the connection details, and run:

```bash
composer install
php testing/main.php
```

It exits with 1 if any test fails.


## Upgrading from 2.x
3.0 has the following breaking changes.

**Queries**
* `PgSqlConnection::query()` no longer has the `$throwExceptionOnError` parameter. It always returns a
  `PgSql\Result`, and always throws `ExceptionQueryError` on failure. Use `try`/`catch` instead of checking for
  `false`.
* `pg_query()` and `pg_connect()` warnings no longer reach your error handler, as the failure is reported through the
  exception instead.

**Escaping**
* `escapeValue()` and `escapeValues()` return `null` as the string `NULL`, and floats as full precision strings,
  rather than returning them unchanged. `escapeValue()` is declared as returning `string|int`.
* `INF`, `-INF` and `NAN` are escaped as PostgreSQL's `Infinity`, `-Infinity` and `NaN`, instead of generating
  invalid SQL.

**Query generators**
* `generateDeleteWhereQuery()` throws `ExceptionNoData` if it's given no where pairs, instead of generating a query
  that deletes every row.
* `generateQueryPairs()` throws `ExceptionNoData` if it's given no pairs, instead of returning an empty string.
* `generateBatchInsertQuery()` throws `ExceptionMismatchedRows` if a row is missing a column that another row has,
  unless that column is in the new `columnsWithDefaults` parameter. Before, the values could silently end up in the
  wrong columns. It also no longer adds a trailing semicolon.
* The table parameter of the select, delete and batch insert generators, and of `escapeTableName()`, is now
  `string|Table $table`, renamed from `$tableName`. This only affects calls that use named arguments.
* A `null` in where pairs now generates `IS NULL`, instead of invalid SQL.

**Classes**
* Every `$conn` parameter in `PgsqlLib` is typed as `PgSql\Connection`.
* `PgSqlConnection` is a `final readonly` class, and `PgsqlLib` is `final` and can't be instantiated, so neither can
  be extended.
* `PgSqlConnection::create()` has a new required `bool $forceNew` parameter, in fifth position, straight after
  `$password`. `$port`, `$useUtf8` and `$useAsync` each move one place later. **Check every call that passes the port
  positionally**, e.g. `create($host, $dbName, $user, $password, 5433)`. In a file without `strict_types`, PHP
  silently converts the port into `forceNew: true` and connects on the default port, 5432, with no error. Named
  arguments avoid this, e.g. `create($host, $dbName, $user, $password, forceNew: true, port: 5433)`.
* `PgSqlConnection::create()`'s `$use_utf8` parameter is renamed to `$useUtf8`. This only affects calls that use
  named arguments.
* `PgSqlConnection::create()` now quotes the values in the connection string, so values with spaces, quotes or
  backslashes work. If you were escaping backslashes yourself to work around this, stop, or they'll be escaped twice.
* `ExceptionUnexpectedValueType`'s default message names the value's type, rather than printing the value.

**Requirements**
* The `programster/pgsql-lib` constraint needs updating, e.g. to `^3.0`.