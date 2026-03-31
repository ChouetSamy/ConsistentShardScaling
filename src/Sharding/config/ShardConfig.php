<?php

namespace App\Sharding\Config;

use App\Sharding\Storage\ShardDefinition;

/**
 * Snapshot de configuration de sharding
 * 
 * Représente un état figé du système de shards à un moment T.
 * "Snapshot" = juste de la DATA (pas de logique/comportement).
 * 
 * RESPONSABILITÉ:
 * - Décrire la topologie: nombre de shards, localisation, credentials
 * - Indiquer la version (pour tracking des migrations)
 * - Pas d'accès base de données
 * - Pas de routage
 * 
 * USAGE:
 * 1. Chaque version de sharding = une config (v1.0, v1.1, v1.2, etc)
 * 2. ShardConfigManager maintient deux configs en migration:
 *    - oldConfig (v1.0): source de données avant migration
 *    - newConfig (v1.1): destination après migration
 * 3. Versioning séquentiel: version doit augmenter (v1 → v2 → v3...)
 * 
 * CYCLE DE VIE:
 *  - Création: chargée depuis YAML/JSON/env
 *  - Utilisation: passée à ShardConfigManager et ShardConnectionManager
 *  - Archivage: gardée après migration (pour audit)
 *  - Suppression: seulement après vérification données complètement migrées
 * 
 * @see ShardConfigManager Pour gestion des versions
 * @see ShardConnectionManager Pour création des connexions
 */
class ShardConfig
{
    /**
     * Numéro de version de cette configuration
     * 
     * Séquentiel et croissant:
     *   - v1 = état initial (3 shards)
     *   - v2 = après scale up (4 shards)
     *   - v3 = après scale down (3 shards)
     *   - etc.
     * 
     * Utilisé pour:
     *   - Validation (currentVersion > oldVersion)
     *   - Audit trail (historique des reconfigurations)
     *   - Ordre des migrations
     * 
     * @var int
     */
    public int $version;

    /**
     * Liste des shards (bases de données) dans cette configuration
     * 
     * Chaque ShardDefinition décrit une base PostgreSQL physique:
     *   id: "shard_1", "shard_2", etc
     *   host, port, dbname: paramètres connexion
     *   user, password: credentials
     * 
     * Ensemble des shards définit la topologie complète.
     * 
     * @var ShardDefinition[]
     */
    public array $shards;

    /**
     * Nombre de virtual nodes par shard dans les HashRings
     * 
     * Configuration du consistent hashing:
     *   - virtualNodes = nombre de copies par shard sur le ring
     *   - Plus = meilleure distribution + plus d'espace mémoire
     *   - Ex: 3 shards × 150 vNodes = 450 positions sur le ring
     * 
     * Typiquement: 150 en production
     * 
     * @var int
     */
    public int $virtualNodes;

    /**
     * Initialise une configuration de sharding
     * 
     * @param int $version Numéro de version (croissant)
     * @param ShardDefinition[] $shards Liste des shards disponibles
     * @param int $virtualNodes Nombre de vNodes par shard (recommandé: 150)
     * 
     * @throws InvalidArgumentException Si données invalides
     */
    public function __construct(int $version, array $shards, int $virtualNodes)
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Version must be >= 1');
        }

        if (empty($shards)) {
            throw new \InvalidArgumentException('At least one shard definition required');
        }

        if ($virtualNodes < 1) {
            throw new \InvalidArgumentException('Virtual nodes must be >= 1');
        }

        $this->version = $version;
        $this->shards = $shards;
        $this->virtualNodes = $virtualNodes;
    }
}