<?php

/*
 * Tests that the batch insert, select and delete generators target the table in the given schema, and that
 * without a schema they still target the table found through the search_path (public by default), as before.
 * "public"."user" and "app"."user" have the same name and columns but different data, so hitting the wrong one shows.
 */

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Table;


class TestSchemaQualifiedTables extends AbstractTest
{
    private const PUBLIC_EMAIL = 'public@example.com';
    private const APP_EMAIL = 'app@example.com';


    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "public"."user", "app"."user", "Odd.Schema""X"."Mixed.Case""Table"');
    }


    public function getDescription() : string
    {
        return "Test that query generators target the correct schema.";
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        $appUser = new Table('user', 'app');

        # Batch insert: one row into public without a schema, one into app with a schema.
        $db->query($db->generateBatchInsertQuery('user', [$this->makeUser('22222222-0000-0000-0000-000000000001', 'public-user', self::PUBLIC_EMAIL)]));
        $db->query($db->generateBatchInsertQuery($appUser, [$this->makeUser('22222222-0000-0000-0000-000000000002', 'app-user', self::APP_EMAIL)]));
        $this->assertSame(['public-user'], $this->fetchNames($db, '"public"."user"'), "batch insert without schema goes to public.user");
        $this->assertSame(['app-user'], $this->fetchNames($db, '"app"."user"'), "batch insert with schema goes to app.user");

        # Select: match every row, so the result shows which table was read.
        $allEmails = ['email' => [self::PUBLIC_EMAIL, self::APP_EMAIL]];
        $selectApp = $db->query($db->generateSelectWhereQuery($appUser, $allEmails, Conjunction::OR));
        $this->assertSame(['app-user'], $this->namesFromResult($selectApp), "select with schema reads app.user");
        $selectDefault = $db->query($db->generateSelectWhereQuery('user', $allEmails, Conjunction::OR));
        $this->assertSame(['public-user'], $this->namesFromResult($selectDefault), "select without schema reads public.user");

        # Delete with schema: only app.user loses its row.
        $db->query($db->generateDeleteWhereQuery($appUser, $allEmails, Conjunction::OR));
        $this->assertSame([], $this->fetchNames($db, '"app"."user"'), "delete with schema empties app.user");
        $this->assertSame(['public-user'], $this->fetchNames($db, '"public"."user"'), "delete with schema leaves public.user alone");

        # Delete without schema: hits public.user, as before.
        $db->query($db->generateBatchInsertQuery($appUser, [$this->makeUser('22222222-0000-0000-0000-000000000003', 'app-user', self::APP_EMAIL)]));
        $db->query($db->generateDeleteWhereQuery('user', $allEmails, Conjunction::OR));
        $this->assertSame([], $this->fetchNames($db, '"public"."user"'), "delete without schema empties public.user");
        $this->assertSame(['app-user'], $this->fetchNames($db, '"app"."user"'), "delete without schema leaves app.user alone");

        # Names that need quoting work end-to-end.
        $oddTable = new Table('Mixed.Case"Table', 'Odd.Schema"X');
        $oddRow = ['id' => '22222222-0000-0000-0000-000000000004', 'name' => 'odd-row'];
        $db->query($db->generateBatchInsertQuery($oddTable, [$oddRow]));
        $selectOdd = $db->query($db->generateSelectWhereQuery($oddTable, ['name' => 'odd-row'], Conjunction::AND));
        $this->assertSame(['odd-row'], $this->namesFromResult($selectOdd), "insert and select on quoted schema/table names");
        $db->query($db->generateDeleteWhereQuery($oddTable, ['name' => 'odd-row'], Conjunction::AND));
        $this->assertSame([], $this->fetchNames($db, $db->escapeTableName($oddTable)), "delete on quoted schema/table names");

        # Without a schema, the search_path decides the table, so it isn't always public.
        $db->query('SET search_path TO "app", "public"');
        $selectSearchPath = $db->query($db->generateSelectWhereQuery(new Table('user'), $allEmails, Conjunction::OR));
        $db->query('RESET search_path');
        $this->assertSame(['app-user'], $this->namesFromResult($selectSearchPath), "Table without a schema follows the search_path");

        $this->m_passed = (count($this->m_errorMessages) === 0);
        $db->query('TRUNCATE "public"."user", "app"."user"');
    }


    private function makeUser(string $id, string $name, string $email) : array
    {
        return ['id' => $id, 'name' => $name, 'email' => $email];
    }


    private function fetchNames(PgSqlConnection $db, string $escapedTableName) : array
    {
        return $this->namesFromResult($db->query("SELECT * FROM {$escapedTableName}"));
    }


    private function namesFromResult(\PgSql\Result $result) : array
    {
        $names = pg_fetch_all_columns($result, pg_field_num($result, 'name'));
        sort($names);
        return $names;
    }


    private function assertSame(array $expected, array $actual, string $label) : void
    {
        if ($expected !== $actual)
        {
            $this->m_errorMessages[] = "{$label}: expected " . json_encode($expected) . " but got " . json_encode($actual);
        }
    }
}
