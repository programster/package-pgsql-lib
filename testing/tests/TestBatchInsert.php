<?php

/*
 * Tests that batch inserting rows works, by inserting two users and selecting them back.
 */

use Programster\PgsqlLib\Conjunction;


class TestBatchInsert extends AbstractTest
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}"); // throws on failure
    }


    public function getDescription() : string
    {
        return "Test that batch inserting data works.";
    }


    public function run() : void
    {
        $users = [
            [
                'id' => '952036f3-3486-4630-9889-9f46b50a54e5',
                'email' => 'user1@gmail.com',
                'name' => 'user1',
            ],
            [
                'id' => '952036f3-3559-4396-9352-81f7dc5a978a',
                'email' => 'user2@gmail.com',
                'name' => 'user2',
            ]
        ];

        $db = ConnectionHandler::getDb();
        $db->query($db->generateBatchInsertQuery("user", $users));

        $wherePairs = [
            'email' => ['user1@gmail.com', 'user2@gmail.com'],
        ];

        $result = $db->query($db->generateSelectWhereQuery("user", $wherePairs, Conjunction::OR));

        if (pg_num_rows($result) !== 2)
        {
            $this->m_errorMessages[] = "Did not have the expected number of user records";
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
    }
}
