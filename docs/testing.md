# Testing

The package is tested against every supported major version of PostgreSQL (14 to 18). To run the tests against all of
them, each in a throwaway Docker container that's removed afterwards, run `composer install`, and then:

```bash
testing/run-all-versions.sh
```

Or pass the versions to test, e.g. `testing/run-all-versions.sh 16 18`. It needs Docker and bash, uses ports 5474 to
5478 (5460 plus the version), and then runs PHPStan. It fails if either the tests or PHPStan do.

To run the tests against one database, **which must be a throwaway one, as they run migrations and empty its tables**,
copy `testing/settings.php.tmpl` to `testing/settings.php`, fill it in, and run:

```bash
php testing/main.php
```

It exits with 1 if any test fails. `testing/tests/TestDocsTransactions.php` runs every example in
[transactions.md](transactions.md), so the documentation can't drift from the code.

The tests also run on GitHub Actions, on every supported version of PHP (8.2 to 8.5), for pushes to `main`, tags and
pull requests.
