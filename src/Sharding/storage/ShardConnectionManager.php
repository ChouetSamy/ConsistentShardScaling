<?php

namespace App\Sharding\Storage;

use App\Sharding\Config\ShardConfig;
use App\Sharding\Storage\ShardDefinition;
use PDO;

/**
 * Gestionnaire des connexions PDO vers les shards
 * 
 * RESPONSABILITÉS:
 *   - Créer et mettre en cache les connexions PDO pour chaque shard
 *   - Fournir une connexion valide au routeur
 *   - Gérer les erreurs de connexion
 * 
 * ARCHITECTURE:
 * Chaque shard dispose d'une connexion PDO persistante en cache (pool de 1).
 * Les connexions sont créées à l'initialization et réutilisées.
 * 
 * À AMÉLIORER (future):
 *   - Pool réel: plusieurs connexions par shard (si workload élevée)
 *   - Reconnexion automatique en cas de timeout
 *   - Health check périodique
 *   - Configuration de timeout
 */
class ShardConnectionManager
{
    /**
     * Cache des connexions PDO par shardId
     * 
     * Structure: [shardId => PDO]
     * Chaque shard a une connexion persistante.
     * 
     * @var array<string, PDO>
     */
    private array $connections = [];

    /**
     * Initialise le gestionnaire avec une configuration de shards
     * 
     * Crée une connexion PDO pour chaque shard défini dans la configuration.
     * Les erreurs de connexion sont propagées (fail-fast).
     * 
     * @param ShardConfig $config Configuration de sharding avec liste des shards
     * 
     * @throws PDOException Si impossible de se connecter à un shard
     * @throws InvalidArgumentException Si configuration invalide
     */
    public function __construct(ShardConfig $config)
    {
        if (empty($config->shards)) {
            throw new \InvalidArgumentException('ShardConfig must contain at least one shard definition');
        }

        foreach ($config->shards as $shardDefinition) {
            $this->connections[$shardDefinition->id] = $this->createConnection($shardDefinition);
        }
    }

    /**
     * Crée une connexion PDO pour un shard
     * 
     * Configuration du PDO:
     *   - Mode erreur: EXCEPTION (erreurs levées comme exceptions)
     *   - Charset: UTF-8 (via DSN PostgreSQL)
     *   - Fetch mode: peut être défini à l'appel (ASSOC, OBJ, etc)
     * 
     * SÉCURITÉ:
     *   ✓ Paramètres depuis ShardDefinition (pas de hardcode)
     *   ✓ Utilisateur/password séparés des credentials secrets
     *   ✓ Error mode exception pour détecter erreurs immédiatement
     * 
     * @param ShardDefinition $shardDefinition Informations de connexion du shard
     * @return PDO Connexion PDO initialisée et configurée
     * 
     * @throws PDOException Si connexion échoue
     */
    private function createConnection(ShardDefinition $shardDefinition): PDO
    {
        try {
            $pdo = new PDO(
                $shardDefinition->getDsn(),
                $shardDefinition->user,
                $shardDefinition->password
            );

            // Configure le mode erreur : lève une exception en cas d'erreur SQL
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (\PDOException $exception) {
            throw new \PDOException(
                \sprintf(
                    'Failed to connect to shard %s at %s:%d: %s',
                    $shardDefinition->id,
                    $shardDefinition->host,
                    $shardDefinition->port,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    /**
     * Récupère la connexion PDO pour un shard spécifique
     * 
     * Retourne la connexion depuis le cache (créée à l'initialization).
     * 
     * GESTION D'ERREURS:
     *   - Shard non trouvé: exception InvalidArgumentException
     *   - Connexion fermée: retourne la connexion (erreur lors execution)
     * 
     * @param string $shardId L'identifiant du shard (ex: "shard_1", "shard_2")
     * @return PDO La connexion PDO vers ce shard
     * 
     * @throws InvalidArgumentException Si shard non reconnue
     */
    public function getConnection(string $shardId): PDO
    {
        if (!isset($this->connections[$shardId])) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Shard "%s" not found. Available shards: %s',
                    $shardId,
                    implode(', ', array_keys($this->connections))
                )
            );
        }

        return $this->connections[$shardId];
    }

    /**
     * Liste tous les shards disponibles
     * 
     * @return array<string> Liste des IDs de shards connues
     */
    public function getAvailableShards(): array
    {
        return array_keys($this->connections);
    }
}