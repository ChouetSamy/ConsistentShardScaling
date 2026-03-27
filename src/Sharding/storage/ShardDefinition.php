<?php
namespace App\Sharding;

/**
 * Représente un shard (une base de données)
 * Cette classe NE fait que décrire la connexion
 */
class ShardDefinition
{
    public string $id;
    public string $host;
    public int $port;
    public string $dbname;
    public string $user;
    public string $password;

    public function __construct(
        string $id,
        string $host,
        int $port,
        string $dbname,
        string $user,
        string $password
    ) {
        $this->id = $id;
        $this->host = $host;
        $this->port = $port;
        $this->dbname = $dbname;
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Génère la DSN pour PDO (PostgreSQL)
     */
    public function getDsn(): string
    {
        return "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";
    }
}