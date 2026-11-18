<?php

/*
 * The versions of UUID that PgsqlLib::generateUuid() can generate (RFC 9562).
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;


enum UuidVersion: int
{
    # The time it was made, to a 10th of a microsecond, then a clock sequence and a random node (rather than the
    # machine's MAC address). It holds the time, but doesn't sort by it. Use it for systems that expect a UUIDv1.
    case V1 = 1;

    # 122 random bits. Use it where a UUID mustn't give away when it was made, or the order things were made in.
    case V4 = 4;

    # The time it was made, to a 4096th of a millisecond, then 62 random bits, so later UUIDs sort after earlier ones.
    # Use it for primary keys, as each new row then goes at the end of the index, rather than somewhere random in it.
    case V7 = 7;
}
