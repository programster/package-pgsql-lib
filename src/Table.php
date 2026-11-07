<?php

/*
 * A table, optionally in a specific schema.
 * Without a schema, the table name is left unqualified, so PostgreSQL resolves it using the connection's search_path.
 * That is not necessarily the public schema, as the search_path may be set per role, database or session, and by
 * default it checks a schema with the same name as the connecting user first.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib;

use Programster\PgsqlLib\Exceptions\ExceptionInvalidTableName;


final readonly class Table
{
    private string $m_name;
    private ?string $m_schemaName;


    /**
     * @param string $name - the name of the table, without any schema prefix, e.g. "user".
     *
     * @param string|null $schemaName - (optional) the name of the schema the table is in, e.g. "app". If null, the
     * table is resolved using the connection's search_path.
     *
     * @throws ExceptionInvalidTableName - if the table name, or the schema name if given, is an empty string.
     */
    public function __construct(string $name, ?string $schemaName = null)
    {
        if ($name === "")
        {
            throw new ExceptionInvalidTableName("The table name must not be empty.");
        }

        if ($schemaName === "")
        {
            throw new ExceptionInvalidTableName(
                "The schema name for table {$name} must not be empty. To use the search_path, pass null instead."
            );
        }

        $this->m_name = $name;
        $this->m_schemaName = $schemaName;
    }


    /**
     * Get the name of the schema the table is in. Check hasSchemaName() first.
     * @return string - the name of the schema.
     * @throws \LogicException - if the table was not given a schema.
     */
    public function getSchemaName() : string
    {
        if ($this->m_schemaName === null)
        {
            throw new \LogicException("Table {$this->m_name} was not given a schema, so it uses the search_path.");
        }

        return $this->m_schemaName;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function hasSchemaName() : bool { return $this->m_schemaName !== null; }
}
