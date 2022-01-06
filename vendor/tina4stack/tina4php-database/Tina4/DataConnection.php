<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * DataConnection adds a connection variable in the constructor to make extending database tools easier
 */
class DataConnection
{
    /**
     * @var Database Database connection
     */
    private $connection;

    /**
     * Constructor for the MetaData class
     * @param Database $connection
     */
    public function __construct(Database $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get the connection
     * @return Database
     */
    final public function getConnection ()
    {
        return $this->connection;
    }

    /**
     * Get the actual resource connection
     * @return mixed
     */
    final public function getDbh ()
    {
        return $this->connection->dbh;
    }
}