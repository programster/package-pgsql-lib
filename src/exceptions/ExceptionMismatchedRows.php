<?php

/*
 * An exception to throw if the rows given for a batch insert don't all have the same columns, and the missing
 * columns have not been declared as having default values.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


class ExceptionMismatchedRows extends AbstractException
{

}
