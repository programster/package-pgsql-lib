<?php

/*
 * The base class for every exception this library throws, so that callers can catch them all in one place.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


abstract class AbstractException extends \Exception
{

}
