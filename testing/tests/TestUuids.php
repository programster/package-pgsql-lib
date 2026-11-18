<?php

/*
 * Tests that PgsqlLib::generateUuid() creates UUIDv7s (RFC 9562) holding the current time, each greater than the one
 * before, which PostgreSQL also sorts in the order they were generated, UUIDv1s holding the time with a random
 * multicast node, and random UUIDv4s, and that isValidUuid() accepts only a UUID in its usual form.
 */

use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;


class TestUuids extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that PgsqlLib generates UUIDv1s, UUIDv4s, and UUIDv7s in order, and checks UUIDs strictly.";
    }


    public function run() : void
    {
        $this->testGenerateV1();
        $this->testGenerateV4();
        $this->testGenerateV7();
        $this->testIsValid();
        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function testGenerateV1() : void
    {
        $count = 10000;
        $gregorianOffset = 122192928000000000;
        $startMicroseconds = (int)floor(microtime(true) * 1000000) - 1;
        $uuids = [];

        for ($i = 0; $i < $count; $i++)
        {
            $uuids[] = PgsqlLib::generateUuid(UuidVersion::V1);
        }

        $endMicroseconds = (int)ceil(microtime(true) * 1000000) + 1;
        $v1Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-1[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';
        $lastTimestamp = 0;

        foreach ($uuids as $uuid)
        {
            [$timeLow, $timeMid, $timeHigh, , $node] = explode('-', $uuid);
            $timestamp = hexdec(substr($timeHigh, 1) . $timeMid . $timeLow);
            $microseconds = intdiv($timestamp - $gregorianOffset, 10);

            if (preg_match($v1Pattern, $uuid) !== 1 || !PgsqlLib::isValidUuid($uuid))
            {
                $this->m_errorMessages[] = "{$uuid} isn't a lowercase UUIDv1.";
            }
            elseif ($microseconds < $startMicroseconds || $microseconds > $endMicroseconds)
            {
                $this->m_errorMessages[] = "{$uuid} doesn't hold the time it was generated.";
            }
            elseif ($timestamp <= $lastTimestamp)
            {
                $this->m_errorMessages[] = "{$uuid}'s time isn't after the one before it.";
            }
            elseif ((hexdec(substr($node, 0, 2)) & 0x01) !== 1)
            {
                $this->m_errorMessages[] = "{$uuid}'s node doesn't have its multicast bit set.";
            }

            $lastTimestamp = $timestamp;
        }

        if (count(array_unique($uuids)) !== $count)
        {
            $this->m_errorMessages[] = "Some of the UUIDv1s are the same.";
        }
    }


    private function testGenerateV4() : void
    {
        $count = 10000;
        $uuids = [];
        $v4Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';

        for ($i = 0; $i < $count; $i++)
        {
            $uuid = PgsqlLib::generateUuid(UuidVersion::V4);

            if (preg_match($v4Pattern, $uuid) !== 1 || !PgsqlLib::isValidUuid($uuid))
            {
                $this->m_errorMessages[] = "{$uuid} isn't a lowercase UUIDv4.";
            }

            $uuids[] = $uuid;
        }

        if (count(array_unique($uuids)) !== $count)
        {
            $this->m_errorMessages[] = "Some of the UUIDv4s are the same.";
        }
    }


    private function testGenerateV7() : void
    {
        $count = 10000;
        $startMilliseconds = (int)floor(microtime(true) * 1000);
        $uuids = [];

        for ($i = 0; $i < $count; $i++)
        {
            $uuids[] = PgsqlLib::generateUuid(UuidVersion::V7);
        }

        $endMilliseconds = (int)ceil(microtime(true) * 1000);
        $v7Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D';

        foreach ($uuids as $uuid)
        {
            $milliseconds = hexdec(substr(str_replace('-', '', $uuid), 0, 12));

            if (preg_match($v7Pattern, $uuid) !== 1 || !PgsqlLib::isValidUuid($uuid))
            {
                $this->m_errorMessages[] = "{$uuid} isn't a lowercase UUIDv7.";
            }
            elseif ($milliseconds < $startMilliseconds || $milliseconds > $endMilliseconds)
            {
                $this->m_errorMessages[] = "{$uuid} doesn't hold the time it was generated.";
            }
        }

        $sorted = $uuids;
        sort($sorted, SORT_STRING);

        if ($sorted !== $uuids || count(array_unique($uuids)) !== $count)
        {
            $this->m_errorMessages[] = "The UUIDs aren't strictly increasing.";
        }

        // PostgreSQL compares uuids by their bytes, so they must sort in the same order there.
        $db = ConnectionHandler::getDb();
        $uuidArray = '{' . implode(',', $uuids) . '}';
        $query = 'SELECT array_agg(u ORDER BY u)::text[] = $1::text[] AS in_order FROM unnest($1::uuid[]) AS u';
        $row = pg_fetch_assoc($db->query($query, [$uuidArray]));

        if ($row === false || $row['in_order'] !== 't')
        {
            $this->m_errorMessages[] = "PostgreSQL doesn't sort the UUIDs in the order they were generated.";
        }
    }


    private function testIsValid() : void
    {
        $valid = [
            '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
            '019A8F2E-5C1B-7D3A-9E4F-0B6C2D8A1F37',
            '952036f3-3486-4630-9889-9f46b50a54e5',
            '00000000-0000-0000-0000-000000000000',
        ];

        foreach ($valid as $uuid)
        {
            if (!PgsqlLib::isValidUuid($uuid))
            {
                $this->m_errorMessages[] = "isValidUuid() rejects {$uuid}.";
            }
        }

        $invalid = [
            '',
            'task-1',
            '019a8f2e5c1b7d3a9e4f0b6c2d8a1f37',
            '{019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37}',
            'urn:uuid:019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
            "019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37\n",
            ' 019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f37',
            '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f3g',
            '019a8f2e-5c1b-7d3a-9e4f-0b6c2d8a1f3',
        ];

        foreach ($invalid as $uuid)
        {
            if (PgsqlLib::isValidUuid($uuid))
            {
                $this->m_errorMessages[] = "isValidUuid() accepts " . var_export($uuid, true) . ".";
            }
        }
    }
}
