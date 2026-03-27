<?php
namespace App\Sharding;

use PDO;

/**
 * Responsable du routage des données vers les bons shards
 */
class ShardRouter
{
    private HashRing $ring;
    private ShardConnectionManager $connectionManager;

    public function __construct(HashRing $ring, ShardConnectionManager $connectionManager)
    {
        $this->ring = $ring;
        $this->connectionManager = $connectionManager;
    }

    /**
     * Détermine le shard pour une clé (email ici)
     */
    public function getShardId(string $key): string
    {
        return $this->ring->getShard($key);
    }

    /**
     * Insère un utilisateur dans le bon shard
     */
    public function insertUser(string $email, string $name): void
    {
        $shardId = $this->getShardId($email);

        $pdo = $this->connectionManager->getConnection($shardId);

        $stmt = $pdo->prepare("
            INSERT INTO users (email, name)
            VALUES (:email, :name)
        ");

        $stmt->execute([
            'email' => $email,
            'name' => $name
        ]);
    }

    /**
     * Récupère un utilisateur
     */
    public function findUser(string $email): ?array
    {
        $shardId = $this->getShardId($email);

        $pdo = $this->connectionManager->getConnection($shardId);

        $stmt = $pdo->prepare("
            SELECT * FROM users WHERE email = :email
        ");

        $stmt->execute(['email' => $email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }
}