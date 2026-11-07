<?php

/*
 * Exception to throw if we encounter an unexpected value type that we aren't sure how to process.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


use Throwable;


class ExceptionUnexpectedValueType extends AbstractException
{
    private readonly mixed $m_unexpectedValue;


    /**
     * Create a new unexpected value type exception.
     * @param mixed $unexpectedValue - the value of a type that was unexpected
     * @param string $message - (optional) the message. Defaults to one describing the unexpected value.
     * @param int $code - (optional) the exception code.
     * @param Throwable|null $previous - (optional) the previous exception, if this was caused by another one.
     */
    public function __construct(
        mixed $unexpectedValue,
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null
    )
    {
        $this->m_unexpectedValue = $unexpectedValue;

        if ($message === "")
        {
            $message = "Unexpected value type: " . get_debug_type($unexpectedValue);
        }

        parent::__construct($message, $code, $previous);
    }


    # Accessors
    public function getUnexpectedValue() : mixed { return $this->m_unexpectedValue; }
}
