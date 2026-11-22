<?php

/*
 * An exception for when an after-commit callback failed. The transaction WAS committed, so it mustn't be run again.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Exceptions;


final class ExceptionAfterCommitFailed extends AbstractException
{
    /**
     * Create an exception for after-commit callbacks failing.
     * @param \Throwable $firstFailure - what the first failed callback threw.
     * @param int $numFailures - how many callbacks failed.
     */
    public function __construct(\Throwable $firstFailure, int $numFailures)
    {
        $message = "The transaction was committed, but {$numFailures} after-commit callback(s) failed: ";
        parent::__construct($message . $firstFailure->getMessage(), 0, $firstFailure);
    }
}
