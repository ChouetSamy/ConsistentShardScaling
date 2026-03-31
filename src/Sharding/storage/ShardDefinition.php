<?php

namespace App\Sharding\Storage;

/**
 * Définition d'un shard (connexion PDO vers une base de données)
 * 
 * RESPONSABILITÉ:
 * - Décrire UNIQUEMENT les paramètres de connexion
 * - Générer la DSN pour PDO
 * - Pas d'accès base de données
 * - Pas de logique sharding
 * 
 * PURE DATA OBJECT:
 * Tous les attributs sont publics (lecture directe, pas de getters).
 * Aucune méthode métier, juste un helper pour génération DSN.
 * 
 * EXEMPLE:
 * ```
 * $shard = new ShardDefinition(
 *     'shard_1',
 *     'db1.example.com',
 *     5432,
 *     'sharding_v1_1',
 *     'app_user',
 *     'secure_password'
 * );
 * 
 * $dsn = $shard->getDsn();
 * // Résultat: "pgsql:host=db1.example.com;port=5432;dbname=sharding_v1_1"
 * 
 * $pdo = new PDO($dsn, $shard->user, $shard->password);
 * ```
 * 
 * AVANTAGES:
 * ✓ Simplifie tests unitaires (pas de dépendances)
 * ✓ Sérialisation easy (JSON/YAML)
 * ✓ Immutable dopo construction (accès données seulement)
 * 
 * @see ShardConnectionManager Pour création des connexions PDO
 * @see ShardConfig Pour liste des shards d'une configuration
 */
class ShardDefinition
{
    /**
     * Identifiant unique du shard (dans la configuration)
     * 
     * Format recommandé: "shard_N" (ex: "shard_1", "shard_2")
     * Utilisé pour:
     *   - Clé dans le dictionnaire de connexions
     *   - Identification dans les logs
     *   - Part du virtual node key (ex: "shard_1#0")
     * 
     * @var string
     */
    public string $id;

    /**
     * Hostname du serveur PostgreSQL
     * 
     * Peut être:
     *   - IP: "192.168.1.10"
     *   - Hostname: "db1.example.com"
     *   - Localhost: "localhost"
     * 
     * @var string
     */
    public string $host;

    /**
     * Port TCP du serveur PostgreSQL
     * 
     * PostgreSQL défault: 5432
     * 
     * @var int
     */
    public int $port;

    /**
     * Nom de la base de données (database name)
     * 
     * Contient les tables:
     *   - users (table applicative)
     *   - Autres tables métier
     * 
     * Format: "dbname_vX" (ex: "sharding_v1", "sharding_v2")
     * Cela permet une DB par version de config (isolement complet).
     * 
     * ⚠️ Alternative: même DB, différentes schemas au lieu de databases
     * Décision: pour ce projet, une DB par version = plus d'isolement
     * 
     * @var string
     */
    public string $dbname;

    /**
     * Utilisateur de connexion PostgreSQL
     * 
     * Credentials pour l'authentification.
     * Peut être un utilisateur spécifique par shard (isolation) ou partagé.
     * 
     * Format recommandé: "app_user" ou "app_shard_read"
     * 
     * @var string
     */
    public string $user;

    /**
     * Mot de passe utilisateur PostgreSQL
     * 
     * ⚠️ SÉCURITÉ:
     *   - NE PAS hardcoder dans le source
     *   - Charger depuis env vars (symfony/dotenv)
     *   - Ou config management système
     *   - Ou vault service (Vault, Consul, etc)
     * 
     * @var string
     */
    public string $password;

    /**
     * Initialise une définition de shard
     * 
     * @param string $id Identifiant unique du shard
     * @param string $host Hostname PostgreSQL
     * @param int $port Port PostgreSQL (défault 5432)
     * @param string $dbname Nom de la base de données
     * @param string $user Utilisateur de connexion
     * @param string $password Mot de passe
     * 
     * @throws InvalidArgumentException Si paramètres invalides
     */
    public function __construct(
        string $id,
        string $host,
        int $port,
        string $dbname,
        string $user,
        string $password
    ) {
        if (empty(trim($id))) {
            throw new \InvalidArgumentException('Shard ID cannot be empty');
        }

        if (empty(trim($host))) {
            throw new \InvalidArgumentException('Shard host cannot be empty');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535');
        }

        if (empty(trim($dbname))) {
            throw new \InvalidArgumentException('Database name cannot be empty');
        }

        if (empty(trim($user))) {
            throw new \InvalidArgumentException('Database user cannot be empty');
        }

        $this->id = $id;
        $this->host = $host;
        $this->port = $port;
        $this->dbname = $dbname;
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Génère la DSN (Data Source Name) pour PDO
     * 
     * DSN = chaîne de connexion parseable par PDO.
     * Format PostgreSQL: "pgsql:host=...;port=...;dbname=..."
     * 
     * EXEMPLE:
     * ```
     * ShardDefinition(...)->getDsn()
     * // Retourne: "pgsql:host=db1.example.com;port=5432;dbname=sharding_v1"
     * ```
     * 
     * USAGE:
     * ```
     * $dsn = $shard->getDsn();
     * $pdo = new PDO($dsn, $shard->user, $shard->password);
     * ```
     * 
     * @return string DSN pour PDO (PostgreSQL)
     */
    public function getDsn(): string
    {
        return \sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->host,
            $this->port,
            $this->dbname
        );
    }
}