<?php

/*
 * Abstract class all tests should extend.
 * A test records any failures in m_errorMessages, and sets m_passed at the end of run(). Throwing an exception
 * from run() also counts as a failure.
 */


abstract class AbstractTest
{
    protected bool $m_passed = false;
    protected array $m_errorMessages = [];


    abstract public function getDescription() : string;
    abstract public function run() : void;


    # Accessors
    public function getPassed() : bool { return $this->m_passed; }
    public function getErrorMessages() : array { return $this->m_errorMessages; }
}
