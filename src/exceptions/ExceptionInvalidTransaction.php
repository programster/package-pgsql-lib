<?php

/*
 * An exception for a transaction being used in a way that can't work, such as running one twice.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


final class ExceptionInvalidTransaction extends AbstractException
{
}
