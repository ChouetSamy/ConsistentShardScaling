<?php
namespace App\Sharding;

use PDO;

class ShardConnectionManager
{
    private array $connections = []; // shardId => PDO

    public function __construct(ShardConfig $config)
    {
        foreach ($config->shards as $shard) {
            $this->connections[$shard->id] = $this->createConnection($shard);
        }
    }

private function createConnection(ShardDefinition $shard): PDO
{
    $pdo = new PDO(
        $shard->getDsn(),
        $shard->user,
        $shard->password
    );
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    return $pdo;
}

public function getConnection(string $shardId): PDO
{
    return $this->connections[$shardId];
}
}