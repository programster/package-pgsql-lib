<?php

/*
 * Tests the class-level guarantees: PgsqlLib is final and can't be instantiated, PgSqlConnection and Table are final
 * and readonly, PgsqlLib types every connection parameter as PgSql\Connection, and create() takes its useUtf8 parameter
 * by that name.
 */

use Programster\PgsqlLib\Exceptions\AbstractException;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\Table;


class TestClassStructure extends AbstractTest
{
    public function getDescription() : string
    {
        return "Test that the library classes are final/readonly and PgsqlLib requires a PgSql\\Connection.";
    }


    public function run() : void
    {
        $lib = new \ReflectionClass(PgsqlLib::class);
        $connection = new \ReflectionClass(PgSqlConnection::class);
        $table = new \ReflectionClass(Table::class);

        $checks = [
            "PgsqlLib is final" => $lib->isFinal(),
            "PgsqlLib's constructor is private" => $lib->getConstructor()->isPrivate(),
            "PgSqlConnection is final" => $connection->isFinal(),
            "PgSqlConnection is readonly" => $connection->isReadOnly(),
            "Table is final" => $table->isFinal(),
            "Table is readonly" => $table->isReadOnly(),
            "create() has a useUtf8 parameter" => in_array('useUtf8', $this->getParameterNames($connection->getMethod('create')), true),
            "create()'s fifth parameter is forceNew" => $this->getParameterNames($connection->getMethod('create'))[4] === 'forceNew',
            "create()'s forceNew parameter is required" => $connection->getMethod('create')->getParameters()[4]->isOptional() === false,
        ];

        foreach ($checks as $label => $passed)
        {
            if ($passed === false)
            {
                $this->m_errorMessages[] = "Expected: {$label}";
            }
        }

        # Every PgsqlLib method that takes a connection must require a PgSql\Connection.
        foreach ($lib->getMethods(\ReflectionMethod::IS_PUBLIC) as $method)
        {
            foreach ($method->getParameters() as $parameter)
            {
                if ($parameter->getName() === 'conn' && (string)$parameter->getType() !== 'PgSql\\Connection')
                {
                    $this->m_errorMessages[] = "PgsqlLib::{$method->getName()}() doesn't type \$conn as PgSql\\Connection";
                }
            }
        }

        # Every exception extends AbstractException, so callers can catch them all in one place.
        foreach (glob(__DIR__ . '/../../src/exceptions/*.php') as $exceptionFile)
        {
            $className = 'Programster\\PgsqlLib\\Exceptions\\' . basename($exceptionFile, '.php');

            if ($className !== AbstractException::class && is_subclass_of($className, AbstractException::class) === false)
            {
                $this->m_errorMessages[] = "{$className} doesn't extend AbstractException";
            }
        }

        $this->m_passed = (count($this->m_errorMessages) === 0);
    }


    private function getParameterNames(\ReflectionMethod $method) : array
    {
        $getName = function (\ReflectionParameter $parameter) : string {
            return $parameter->getName();
        };

        return array_map($getName, $method->getParameters());
    }
}
