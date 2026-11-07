<?php

/*
 * The conjunction used to join the conditions of a where clause.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;


enum Conjunction: string
{
    case AND = "AND";
    case OR = "OR";
}
