<?php

/*
 * An exception to throw if a Table is given an empty table or schema name, which would generate invalid SQL.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


class ExceptionInvalidTableName extends AbstractException
{

}
