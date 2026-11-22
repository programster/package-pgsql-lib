<?php

/*
 * An ordered collection of callbacks.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use Programster\Collections\AbstractCollection;

/**
 * @extends AbstractCollection<\Closure>
 */
final class ClosureCollection extends AbstractCollection
{
    public function __construct(\Closure ...$closures)
    {
        parent::__construct(...$closures);
    }
}
