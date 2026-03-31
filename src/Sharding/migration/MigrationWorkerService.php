<?php

namespace App\Sharding\Migration;

use App\Sharding\Storage\ShardConnectionManager;
use App\Sharding\ShardException;
use Psr\Log\LoggerInterface;
use PDO;

/**
 * Service de migration batch (worker mode)
 * 
 * CONCEPT (Migration "WORKER"):
 * Un worker batch isolé qui migre ALL data d'oldShard vers newShard, sans attendre les accès.
 * Approche proactive vs la migration lazy (réactive).
 * 
 * PROCESS:
 * 1. Lis TOUS les utilisateurs de chaque oldShard
 * 2. Pour chaque utilisateur:
 *    a. INSERT INTO newShard (ignorer conflits)
 *    b. DELETE FROM oldShard
 * 3. Accumule statistiques et erreurs
 * 4. Retourne rapport final
 * 
 * AVANTAGES:
 *   ✓ Rapide: migre tout sans attendre les accès
 *   ✓ Prévisible: durée estimable, peut paralléliser
 *   ✓ Complet: données froides aussi migrées
 *   ✓ Batch: utilise bulk operations (plus rapide)
 * 
 * DÉSAVANTAGES:
 *   ⚠️  Impact I/O: charge importante sur les bases (lire toutes les données)
 *   ⚠️  Downtime possible: si worker et production concurrente
 *   ⚠️  Locking: risque de deadlock avec autres operations
 * 
 * STRATÉGIE RECOMMANDÉE:
 * 1. Phase 1: Migration lazy (during normal traffic) - capture données accédées
 * 2. Phase 2: Worker batch (during low traffic) - capture données restantes
 * 3. Phase 3: Cleanup (vérification et archivage final)
 * 
 * Cette combinaison: minim impact + migration complète
 * 
 * @see MigrationThroughService Pour phase 1 (lazy migration)
 */
class MigrationWorkerService
{
    /**
     * Gestionnaire de connexions aux shards
     * 
     * @var ShardConnectionManager
     */
    private ShardConnectionManager $connectionManager;

    /**
     * ID du shard source (vieille configuration)
     * 
     * @var string
     */
    private string $oldShardId;

    /**
     * ID du shard destination (nouvelle configuration)
     * 
     * @var string
     */
    private string $newShardId;

    /**
     * Logger pour tracer progression, erreurs, métriques
     * 
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Taille du batch pour paginer la migration
     * 
     * Valeur recommandée: 100-1000 (compromise entre mémoire et I/O)
     * 
     * @var int
     */
    private int $batchSize = 500;

    /**
     * Statistiques d'exécution
     * 
     * @var array
     */
    private array $stats = [
        'totalUsers' => 0,
        'usersMigrated' => 0,
        'usersFailed' => 0,
        'usersSkipped' => 0,
        'durationSeconds' => 0,
    ];

    /**
     * Initialise le worker
     * 
     * @param ShardConnectionManager $connectionManager Gestionnaire connexions
     * @param string $oldShardId ID du shard source (vieille config)
     * @param string $newShardId ID du shard destination (nouvelle config)
     * @param LoggerInterface|null $logger Logger optionnel
     * @param int $batchSize Taille du batch pour paginer (défault 500)
     */
    public function __construct(
        ShardConnectionManager $connectionManager,
        string $oldShardId,
        string $newShardId,
        ?LoggerInterface $logger = null,
        int $batchSize = 500
    ) {
        $this->connectionManager = $connectionManager;
        $this->oldShardId = $oldShardId;
        $this->newShardId = $newShardId;
        $this->logger = $logger;
        $this->batchSize = $batchSize;
    }

    /**
     * Exécute la migration complète du shard
     * 
     * PROCESS:
     * 1. Compte total utilisateurs à migrer
     * 2. Itère par batches (pagination)
     * 3. Pour chaque batch:
     *    a. Lis utilisateurs depuis oldShard
     *    b. Copie vers newShard (INSERT CONFLICT DO NOTHING)
     *    c. Archive depuis oldShard (DELETE) après succès
     * 4. Accumule stats et logs les erreurs
     * 5. Retourne rapport final
     * 
     * GESTION D'ERREURS:
     *   - Erreur insertion: log + skip user (pas de rollback)
     *   - Erreur deletion: log + continue (user reste en oldShard)
     *   - Erreur lecture: log + stop batch (retry possible)
     * 
     * @return array Rapport final avec statistiques
     * 
     * @throws ShardException Si erreur critique (début du process)
     */
    public function migrate(): array
    {
        $startTime = microtime(true);

        $this->logger?->info('Starting batch migration', [
            'oldShardId' => $this->oldShardId,
            'newShardId' => $this->newShardId,
            'batchSize' => $this->batchSize,
        ]);

        try {
            // ÉTAPE 1: Compte utilisateurs à migrer
            $this->stats['totalUsers'] = $this->countUsersInShard($this->oldShardId);

            $this->logger?->info('Users to migrate', [
                'count' => $this->stats['totalUsers'],
            ]);

            if ($this->stats['totalUsers'] === 0) {
                $this->logger?->info('No users to migrate in oldShard');
                return $this->stats;
            }

            // ÉTAPE 2: Itère par batches
            $offset = 0;
            while ($offset < $this->stats['totalUsers']) {
                $this->logger?->debug('Processing batch', [
                    'offset' => $offset,
                    'batchSize' => $this->batchSize,
                ]);

                $users = $this->fetchUsersBatch($this->oldShardId, $this->batchSize, $offset);

                // ÉTAPE 3: Migre chaque utilisateur du batch
                foreach ($users as $user) {
                    $this->migrateUserSafely($user);
                }

                $offset += $this->batchSize;
            }

            $this->stats['durationSeconds'] = round(microtime(true) - $startTime, 2);

            $this->logger?->info('Batch migration completed', $this->stats);

            return $this->stats;
        } catch (\Exception $exception) {
            $this->logger?->error('Batch migration failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new ShardException(
                \sprintf('Batch migration failed: %s', $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Compte le nombre total d'utilisateurs dans un shard
     * 
     * @param string $shardId ID du shard à compter
     * @return int Nombre d'utilisateurs
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function countUsersInShard(string $shardId): int
    {
        $pdo = $this->connectionManager->getConnection($shardId);

        $statement = $pdo->prepare('
            SELECT COUNT(*) as total
            FROM users
        ');

        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return (int)$result['total'];
    }

    /**
     * Récupère un batch d'utilisateurs depuis oldShard
     * 
     * PAGINATION:
     *   LIMIT :batchSize OFFSET :offset
     * Permet de traiter par chunks sans charger tout en mémoire.
     * 
     * @param string $shardId ID du shard source
     * @param int $limit Nombre d'users à récupérer
     * @param int $offset Décalage dans les résultats
     * @return array<array> Liste des utilisateurs du batch
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function fetchUsersBatch(string $shardId, int $limit, int $offset): array
    {
        $pdo = $this->connectionManager->getConnection($shardId);

        $statement = $pdo->prepare('
            SELECT id, email, name, created_at, updated_at
            FROM users
            ORDER BY id ASC
            LIMIT :limit OFFSET :offset
        ');

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Migre un utilisateur de manière sûre (gestion erreurs)
     * 
     * ÉTAPES:
     * 1. Copie vers newShard (INSERT CONFLICT DO NOTHING)
     * 2. Si succès: archive depuis oldShard (DELETE)
     * 3. Si erreur: log et continue (pas de stop du worker)
     * 
     * @param array $user Données utilisateur à migrer
     * @return void
     */
    private function migrateUserSafely(array $user): void
    {
        $email = $user['email'];

        try {
            // ÉTAPE 1: Copie vers newShard
            $this->copyUserToNewShard($user);

            // ÉTAPE 2: Archive depuis oldShard
            $this->archiveUserFromOldShard($email);

            $this->stats['usersMigrated']++;

            $this->logger?->debug('User migrated', ['email' => $email]);
        } catch (\Exception $exception) {
            $this->stats['usersFailed']++;

            $this->logger?->warning('Failed to migrate user', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Copie un utilisateur vers le nouveau shard
     * 
     * Utilise INSERT CONFLICT DO NOTHING pour idempotence.
     * Si user existe déjà (migration partielle), l'insertion est ignorée.
     * 
     * @param array $user Données utilisateur
     * @return void
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function copyUserToNewShard(array $user): void
    {
        $pdo = $this->connectionManager->getConnection($this->newShardId);

        $statement = $pdo->prepare('
            INSERT INTO users (id, email, name, created_at, updated_at)
            VALUES (:id, :email, :name, :created_at, :updated_at)
            ON CONFLICT (email) DO NOTHING
        ');

        $statement->execute([
            ':id' => $user['id'],
            ':email' => $user['email'],
            ':name' => $user['name'],
            ':created_at' => $user['created_at'],
            ':updated_at' => $user['updated_at'],
        ]);
    }

    /**
     * Archive (supprime) un utilisateur depuis l'ancien shard
     * 
     * @param string $email Email de l'utilisateur à archiver
     * @return void
     * 
     * @throws \PDOException Si erreur accès base de données
     */
    private function archiveUserFromOldShard(string $email): void
    {
        $pdo = $this->connectionManager->getConnection($this->oldShardId);

        $statement = $pdo->prepare('
            DELETE FROM users
            WHERE email = :email
        ');

        $statement->execute([':email' => $email]);
    }

    /**
     * Récupère les statistiques finales de migration
     * 
     * @return array Dictionnaire avec clés:
     *   - totalUsers: nombre total à migrer
     *   - usersMigrated: nombre réussi
     *   - usersFailed: nombre échoué
     *   - usersSkipped: nombre ignoré
     *   - durationSeconds: durée exécution (secondes)
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Retourne un rapport d'exécution formaté
     * 
     * @return string Rapport texte
     */
    public function getMigrationReport(): string
    {
        $successRate = ($this->stats['totalUsers'] > 0)
            ? round(($this->stats['usersMigrated'] / $this->stats['totalUsers']) * 100, 2)
            : 100;

        return \sprintf(
            "Batch Migration Report:\n" .
            "  Total users: %d\n" .
            "  Migrated: %d\n" .
            "  Failed: %d\n" .
            "  Skipped: %d\n" .
            "  Success rate: %.2f%%\n" .
            "  Duration: %d seconds\n",
            $this->stats['totalUsers'],
            $this->stats['usersMigrated'],
            $this->stats['usersFailed'],
            $this->stats['usersSkipped'],
            $successRate,
            $this->stats['durationSeconds']
        );
    }
}
