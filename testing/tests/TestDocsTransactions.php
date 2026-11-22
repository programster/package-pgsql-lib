<?php

/*
 * Runs every PHP example in docs/transactions.md, in order, so that the documentation can't drift from the code.
 */

class TestDocsTransactions extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that every example in docs/transactions.md runs.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP TABLE IF EXISTS "transfer"; DROP TABLE IF EXISTS "account"');
        $db->query('CREATE TABLE "account" ("id" int PRIMARY KEY, "balance" int NOT NULL)');
        $db->query('CREATE TABLE "transfer" ("from_id" int NOT NULL, "to_id" int NOT NULL)');
        $db->query('INSERT INTO "account" ("id", "balance") VALUES (1, 1000), (2, 1000)');
        $markdown = file_get_contents(__DIR__ . '/../../docs/transactions.md');
        preg_match_all('/```php\n(.*?)```/s', $markdown, $matches);

        if (count($matches[1]) === 0)
        {
            $this->m_errorMessages[] = "No examples were found in docs/transactions.md.";
        }

        try
        {
            foreach ($matches[1] as $index => $example)
            {
                $this->runExample($db, $example, $index + 1);
            }
        }
        finally
        {
            if ($db->isInTransaction())
            {
                $db->query('ROLLBACK');
            }

            $db->query('DROP TABLE "transfer"; DROP TABLE "account"');
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function runExample(Programster\PgsqlLib\PgSqlConnection $db, string $example, int $number) : void
    {
        $fromId = 1;
        $toId = 2;

        try
        {
            eval($example);
        }
        catch (\Throwable $ex)
        {
            $this->m_errorMessages[] = "Example {$number} threw " . get_class($ex) . ": {$ex->getMessage()}";
        }

        if ($db->isInTransaction())
        {
            $this->m_errorMessages[] = "Example {$number} left a transaction open.";
            $db->query('ROLLBACK');
        }
    }
}
