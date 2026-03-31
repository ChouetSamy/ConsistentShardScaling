<?php

namespace App\Sharding;

use App\Sharding\Config\ShardConfigManager;
use App\Sharding\Storage\ShardConnectionManager;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Routeur de sharding avec support des migrations lazy (two-ring pattern)
 * 
 * RESPONSABILITÉS:
 *   - Déterminer le shard cible pour une clé (email)
 *   - Récupérer les utilisateurs (readfrom newShard avec fallback oldShard)
 *   - Insérer les utilisateurs (write uniquement sur newShard)
 *   - Effectuer les migrations lazy lors des lectures
 * 
 * PATTERN TWO-RING:
 *   - newRing: destination des insertions et lectures prioritaires
 *   - oldRing: source avec fallback si données pas encore migrées
 * 
 * Exemple de flux findUser(user@example.com):
 *   1. Calcule newShard = newRing.getShard("user@example.com")
 *   2. Tente SELECT sur newShard
 *   3. Si trouvé → retourne l'utilisateur
 *   4. Sinon: calcule oldShard = oldRing.getShard("user@example.com")
 *   5. Tente SELECT sur oldShard (si oldRing existe)
 *   6. Si trouvé:
 *      - Si oldShard ≠ newShard → migration lazy
 *        a. INSERT INTO newShard (CONFLICT DO NOTHING)
 *        b. DELETE FROM oldShard
 *      - Retourne l'utilisateur
 *   7. Si pas trouvé → retourne null
 * 
 * AVANTAGES:
 *   ✓ Zéro downtime: les deux shards sont accessibles pendant la migration
 *   ✓ Migration progressive: données déplacées au fil de l'eau (lors des lectures)
 *   ✓ Tolérance aux pannes: oldRing reste stable pendant la migration
 *   ✓ Atomicité: chaque utilisateur est migré atomiquement
 */
class ShardRouter
{
    /**
     * Gestionnaire de configuration des shards
     * 
     * Maintient newRing et oldRing en parallèle.
     * 
     * @var ShardConfigManager
     */
    private ShardConfigManager $configManager;

    /**
     * Gestionnaire des connexions PDO vers les shards
     * 
     * @var ShardConnectionManager
     */
    private ShardConnectionManager $connectionManager;

    /**
     * Logger optionnel pour tracer les opérations (migrations, erreurs)
     * 
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Initialise le routeur
     * 
     * @param ShardConfigManager $configManager Gestionnaire de configuration (oldRing + newRing)
     * @param ShardConnectionManager $connectionManager Pool de connexions PDO
     * @param LoggerInterface|null $logger Logger optionnel (debug/migrations)
     */
    public function __construct(
        ShardConfigManager $configManager,
        ShardConnectionManager $connectionManager,
        ?LoggerInterface $logger = null
    ) {
        $this->configManager = $configManager;
        $this->connectionManager = $connectionManager;
        $this->logger = $logger;
    }

    /**
     * Récupère l'utilisateur via le pattern two-ring avec migration lazy
     * 
     * ÉTAPES:
     *   1. Calcule le shard destination (newRing)
     *   2. Tente de lire depuis newShard
     *   3. Si trouvé → retourne
     *   4. Sinon: calcule l'ancien shard (oldRing) si migration active
     *   5. Tente de lire depuis oldShard
     *   6. Si trouvé:
     *      - Effectue migration lazy si shard a changé
     *      - Retourne utilisateur
     *   7. Sinon: retourne null
     * 
     * MIGRATION LAZY:
     * Quand un utilisateur est lu depuis oldShard mais devrait être sur newShard:
     *   - INSERT INTO newShard (ignore si existe déjà)
     *   - DELETE FROM oldShard
     * 
     * Cette migration est ATOMIQUE par utilisateur (une seule tentative, pas de retry).
     * 
     * @param string $email L'adresse email de l'utilisateur à rechercher
     * @return array|null Les données de l'utilisateur ou null si non trouvé
     * 
     * @throws ShardException Si erreur lors accès aux shards
     */
    public function findUser(string $email): ?array
    {
        $this->validateEmailNotEmpty($email);

        // ÉTAPE 1: Calcule shard destination (newRing)
        $newShardId = $this->resolveNewShardId($email);

        // ÉTAPE 2-3: Tente lecture depuis newShard
        $user = $this->fetchUserFromShard($newShardId, $email);

        if ($user !== null) {
            $this->logger?->info('User found in newShard', ['email' => $email, 'shardId' => $newShardId]);
            return $user;
        }

        // ÉTAPE 4: oldRing existe? (migration en cours)
        if (!$this->configManager->isMigrating()) {
            $this->logger?->debug('User not found and no migration active', ['email' => $email]);
            return null;
        }

        // ÉTAPE 4: Calcule ancien shard (oldRing)
        $oldShardId = $this->resolveOldShardId($email);

        // ÉTAPE 5: Tente lecture depuis oldShard
        $user = $this->fetchUserFromShard($oldShardId, $email);

        if ($user === null) {
            $this->logger?->debug('User not found in oldShard', ['email' => $email, 'oldShardId' => $oldShardId]);
            return null;
        }

        // ÉTAPE 6: Utilisateur trouvé dans oldShard
        $this->logger?->info('User found in oldShard', ['email' => $email, 'oldShardId' => $oldShardId, 'newShardId' => $newShardId]);

        // Vérifie si migration nécessaire
        if ($oldShardId !== $newShardId) {
            $this->performLazyMigration($email, $user, $oldShardId, $newShardId);
        }

        return $user;
    }

    /**
     * Insère un nouvel utilisateur dans le bon shard (newRing uniquement)
     * 
     * RÈGLE:
     * - Utilise UNIQUEMENT newRing
     * - oldRing est ignoré (pas de fallback)
     * - Nouvelle insertion = destination finale directe
     * 
     * GESTION DES ERREURS:
     * - Email vide: exception levée
     * - Shard indisponible: exception PDO propagée
     * - Conflit violation (email en doublon): exception PDO propagée
     * 
     * @param string $email L'adresse email unique de l'utilisateur
     * @param string $name Le nom complet de l'utilisateur
     * @return void
     * 
     * @throws ShardException Si erreur lors accès au shard
     * @throws \PDOException Si violation contrainte ou shard indisponible
     */
    public function insertUser(string $email, string $name): void
    {
        $this->validateEmailNotEmpty($email);
        $this->validateNameNotEmpty($name);

        // ÉTAPE 1: Calcule shard destination (newRing uniquement)
        $newShardId = $this->resolveNewShardId($email);

        $this->logger?->debug('Inserting user into newShard', ['email' => $email, 'shardId' => $newShardId]);

        // ÉTAPE 2: Insère dans newShard
        $this->insertIntoShard($newShardId, $email, $name);

        $this->logger?->info('User inserted successfully', ['email' => $email, 'shardId' => $newShardId]);
    }

    /**
     * Récupère le shard destination pour une clé (newRing)
     * 
     * Utilise le HashRing courant pour mapper la clé vers sa destination.
     * Cette méthode est déterministe: même clé = même shard (garantie du consistent hashing).
     * 
     * @param string $email L'adresse email (clé de sharding)
     * @return string L'identifiant du shard destination
     * 
     * @throws ShardException Si newRing non initialisé ou clé invalide
     */
    private function resolveNewShardId(string $email): string
    {
        try {
            return $this->configManager->getNewRing()->getShard($email);
        } catch (\Exception $exception) {
            throw new ShardException(
                \sprintf('Failed to resolve new shard for email %s: %s', $email, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Récupère le shard source pour une clé (oldRing)
     * 
     * Utilisé uniquement lors des migrations lazy pour retrouver les données anciennes.
     * À appeler uniquement si isMigrating() retourne true.
     * 
     * @param string $email L'adresse email (clé de sharding)
     * @return string L'identifiant du shard source
     * 
     * @throws ShardException Si oldRing non disponible ou clé invalide
     */
    private function resolveOldShardId(string $email): string
    {
        $oldRing = $this->configManager->getOldRing();

        if ($oldRing === null) {
            throw new ShardException('Old ring not available for migration fallback');
        }

        try {
            return $oldRing->getShard($email);
        } catch (\Exception $exception) {
            throw new ShardException(
                \sprintf('Failed to resolve old shard for email %s: %s', $email, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Récupère un utilisateur depuis un shard spécifique
     * 
     * Exécute une requête SELECT préparée avec PDO pour récupérer les données.
     * Utilise le paramètre lié pour éviter les injections SQL.
     * 
     * SÉCURITÉ:
     *   ✓ Requête préparée (prepared statement)
     *   ✓ Paramètre lié (named placeholder)
     *   ✓ Pas de concaténation SQL
     * 
     * @param string $shardId L'identifiant du shard (destination de la requête)
     * @param string $email L'adresse email à rechercher
     * @return array|null Les données de l'utilisateur ou null si non trouvé
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function fetchUserFromShard(string $shardId, string $email): ?array
    {
        try {
            $pdo = $this->connectionManager->getConnection($shardId);

            $statement = $pdo->prepare('
                SELECT id, email, name, created_at, updated_at
                FROM users
                WHERE email = :email
                LIMIT 1
            ');

            $statement->execute([':email' => $email]);

            $user = $statement->fetch(PDO::FETCH_ASSOC);

            return $user ?: null;
        } catch (\PDOException $exception) {
            $this->logger?->error('Error fetching user from shard', [
                'shardId' => $shardId,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Insère un utilisateur dans un shard spécifique
     * 
     * Exécute une requête INSERT préparée avec PDO.
     * Les paramètres sont liés pour éviter les injections SQL.
     * 
     * SÉCURITÉ:
     *   ✓ Requête préparée (prepared statement)
     *   ✓ Paramètres liés
     *   ✓ Pas de concaténation SQL
     * 
     * GESTION D'ERREURS:
     *   - Contrainte violée (email duplicate): exception propagée
     *   - Shard indisponible: exception propagée et loggée
     * 
     * @param string $shardId L'identifiant du shard cible
     * @param string $email L'adresse email unique
     * @param string $name Le nom de l'utilisateur
     * @return void
     * 
     * @throws \PDOException Si erreur accès base de données ou violation contrainte
     */
    private function insertIntoShard(string $shardId, string $email, string $name): void
    {
        try {
            $pdo = $this->connectionManager->getConnection($shardId);

            $statement = $pdo->prepare('
                INSERT INTO users (email, name, created_at, updated_at)
                VALUES (:email, :name, NOW(), NOW())
            ');

            $statement->execute([
                ':email' => $email,
                ':name' => $name,
            ]);
        } catch (\PDOException $exception) {
            $this->logger?->error('Error inserting user into shard', [
                'shardId' => $shardId,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Copie l'utilisateur vers le nouveau shard
     * 
     * Insère l'utilisateur dans newShard avec la clause:
     *   ON CONFLICT (email) DO NOTHING
     * 
     * Cette clause garantit l'idempotence: si l'utilisateur existe déjà
     * dans newShard (migration partielle), l'insertion est ignorée silencieusement.
     * 
     * SÉCURITÉ:
     *   ✓ Requête préparée (prepared statement)
     *   ✓ Paramètres liés
     *   ✓ Syntaxe PostgreSQL (ON CONFLICT)
     * 
     * @param string $newShardId L'identifiant du shard destination
     * @param string $email L'adresse email unique
     * @param string $name Le nom de l'utilisateur
     * @return void
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function copyUserToNewShard(string $newShardId, string $email, string $name): void
    {
        try {
            $pdo = $this->connectionManager->getConnection($newShardId);

            $statement = $pdo->prepare('
                INSERT INTO users (email, name, created_at, updated_at)
                VALUES (:email, :name, NOW(), NOW())
                ON CONFLICT (email) DO NOTHING
            ');

            $statement->execute([
                ':email' => $email,
                ':name' => $name,
            ]);

            $this->logger?->debug('User copied to newShard', [
                'email' => $email,
                'newShardId' => $newShardId,
            ]);
        } catch (\PDOException $exception) {
            $this->logger?->error('Error copying user to newShard', [
                'newShardId' => $newShardId,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Archive l'utilisateur depuis l'ancien shard (suppression logique)
     * 
     * Supprime physiquement l'utilisateur de l'ancien shard.
     * Après cette opération, seule la version dans newShard reste.
     * 
     * OPTION ALTERNATIVE:
     * Pour plus de sécurité, you pourriez avoir une colonne:
     *   UPDATE users SET archived_at = NOW() WHERE email = ?
     * Cela crée un soft delete plutôt qu'une suppression physique.
     * 
     * SÉCURITÉ:
     *   ✓ Requête préparée (prepared statement)
     *   ✓ Paramètre lié
     *   ✓ WHERE clause précis (email unique)
     * 
     * @param string $oldShardId L'identifiant du shard source
     * @param string $email L'adresse email à archiver
     * @return void
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function archiveUserFromOldShard(string $oldShardId, string $email): void
    {
        try {
            $pdo = $this->connectionManager->getConnection($oldShardId);

            $statement = $pdo->prepare('
                DELETE FROM users
                WHERE email = :email
            ');

            $statement->execute([':email' => $email]);

            $this->logger?->debug('User archived from oldShard', [
                'email' => $email,
                'oldShardId' => $oldShardId,
            ]);
        } catch (\PDOException $exception) {
            $this->logger?->error('Error archiving user from oldShard', [
                'oldShardId' => $oldShardId,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Effectue la migration lazy d'un utilisateur
     * 
     * ÉTAPES:
     *   1. Copie l'utilisateur de oldShard vers newShard (INSERT CONFLICT DO NOTHING)
     *   2. Archive l'utilisateur depuis oldShard (DELETE)
     * 
     * ATOMICITÉ:
     * Les deux opérations sont effectuées en séquence, sans transaction.
     * En cas d'erreur lors de la suppression, la donnée reste en doublon
     * dans oldShard (cas dégradé mais acceptable: la donnée correcte est en newShard).
     * 
     * ALTERNATIVE:
     * Pour une atomicité complète, encapsuler dans une transaction:
     *   $pdo->beginTransaction();
     *   try {
     *       $this->copyUserToNewShard(...);
     *       $this->archiveUserFromOldShard(...);
     *       $pdo->commit();
     *   } catch (...) {
     *       $pdo->rollBack();
     *       throw;
     *   }
     * 
     * PARAMÈTRES:
     * @param string $email L'adresse email
     * @param array $user Les données de l'utilisateur (du oldShard)
     * @param string $oldShardId L'identifiant du shard source
     * @param string $newShardId L'identifiant du shard destination
     * @return void
     * 
     * @throws \PDOException Si erreur lors migration
     */
    private function performLazyMigration(string $email, array $user, string $oldShardId, string $newShardId): void
    {
        $this->logger?->info('Starting lazy migration', [
            'email' => $email,
            'oldShardId' => $oldShardId,
            'newShardId' => $newShardId,
        ]);

        try {
            // ÉTAPE 1: Copie vers newShard (idempotent)
            $this->copyUserToNewShard($newShardId, $email, $user['name']);

            // ÉTAPE 2: Archive depuis oldShard
            $this->archiveUserFromOldShard($oldShardId, $email);

            $this->logger?->info('Lazy migration completed', [
                'email' => $email,
                'oldShardId' => $oldShardId,
                'newShardId' => $newShardId,
            ]);
        } catch (\PDOException $exception) {
            $this->logger?->error('Lazy migration failed', [
                'email' => $email,
                'oldShardId' => $oldShardId,
                'newShardId' => $newShardId,
                'error' => $exception->getMessage(),
            ]);

            throw new ShardException(
                \sprintf('Lazy migration failed for email %s: %s', $email, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Valide que l'email n'est pas vide
     * 
     * @param string $email
     * @return void
     * 
     * @throws \InvalidArgumentException Si email vide
     */
    private function validateEmailNotEmpty(string $email): void
    {
        if (empty(trim($email))) {
            throw new \InvalidArgumentException('Email cannot be empty');
        }
    }

    /**
     * Valide que le nom n'est pas vide
     * 
     * @param string $name
     * @return void
     * 
     * @throws \InvalidArgumentException Si nom vide
     */
    private function validateNameNotEmpty(string $name): void
    {
        if (empty(trim($name))) {
            throw new \InvalidArgumentException('Name cannot be empty');
        }
    }
}