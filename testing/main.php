<?php

/*
 * Runs the migrations, then every test in the tests folder, and prints a summary.
 * Exits with 1 if any test failed, so that CI can detect failures.
 */

// run-all-versions.sh sets the connection details through TEST_ environment variables, and they replace settings.php
// entirely, so that a run against its throwaway containers can never touch the database in that file.
if (getenv('TEST_DB_HOST') !== false)
{
    foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $setting)
    {
        define($setting, (string)getenv("TEST_{$setting}"));
    }
}
else
{
    require_once(__DIR__ . '/settings.php');
}

require_once(__DIR__ . '/../vendor/autoload.php');

$autoloader = new \Programster\Autoloader\Autoloader([
    __DIR__,
    __DIR__ . '/tests',
]);

$migrationManager = new Programster\PgsqlMigrations\MigrationManager(
    __DIR__ . '/migrations',
    ConnectionHandler::getDb()->getResource()
);

$migrationManager->migrate();

print "Testing against PostgreSQL " . (pg_version(ConnectionHandler::getDb()->getResource())['server'] ?? 'unknown') . PHP_EOL;

$boldGreen = "\033[1;32m";
$boldRed = "\033[1;31m";
$boldCyan = "\033[1;36m";
$reset = "\033[0m";

$testFiles = glob(__DIR__ . '/tests/*.php');
$testResults = [];
$testFailureMessages = [];

foreach ($testFiles as $testFile)
{
    $testName = basename($testFile, ".php");

    // Discard anything the test prints, so that only the summary is shown.
    ob_start();

    try
    {
        /* @var $test AbstractTest */
        $test = new $testName();
        $test->run();

        if ($test->getPassed())
        {
            $testResults[$testName] = "passed";
        }
        else
        {
            $testResults[$testName] = "failed";
            $testFailureMessages[$testName] = implode(PHP_EOL, $test->getErrorMessages());
        }
    }
    catch (Throwable $ex)
    {
        $testResults[$testName] = "failed";
        $testFailureMessages[$testName] = get_class($ex) . ": " . $ex->getMessage();
    }

    ob_end_clean();
}

arsort($testResults);

foreach ($testResults as $testName => $passedOrFailed)
{
    if ($passedOrFailed === "passed")
    {
        print "{$boldCyan}{$testName}{$reset}: {$boldGreen}PASSED{$reset}" . PHP_EOL;
    }
    else
    {
        print "{$boldCyan}{$testName}{$reset}: {$boldRed}FAILED{$reset} - {$testFailureMessages[$testName]}" . PHP_EOL;
    }
}

$numFailed = count($testFailureMessages);
print PHP_EOL . (count($testResults) - $numFailed) . " passed, {$numFailed} failed" . PHP_EOL;
exit(($numFailed > 0) ? 1 : 0);
