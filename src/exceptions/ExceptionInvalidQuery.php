<?php

/*
 * An exception for SQL that can't be used in a Query, which is thrown where the Query is created.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


final class ExceptionInvalidQuery extends AbstractException
{
}
