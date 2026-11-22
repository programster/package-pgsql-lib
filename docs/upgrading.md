# Upgrading

Newest first.

## 3.3.0

Nothing needs changing. This adds:

- `Transaction`, `Query` and `QueryCollection`, and `PgSqlConnection::runInTransaction()` and `isInTransaction()`. See
  [Transactions](transactions.md).
- `Transaction` can be extended, with `prepareQueries()` and `afterSuccess()`.
- `ExceptionQueryError::getSqlState()` and `isRetryable()`.
- A new dependency on `programster/collections`.

## 3.0.0

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
