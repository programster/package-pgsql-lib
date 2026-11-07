<?php

/*
 * An exception to throw if a method is given no data to operate on, such as no rows to insert, or no where pairs
 * to delete by.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


class ExceptionNoData extends AbstractException
{

}
