<?php

namespace App\Sharding\Migration;

use App\Sharding\ShardRouter;
use App\Sharding\ShardException;
use Psr\Log\LoggerInterface;

/**
 * Service de migration au fil de l'eau (reactive/lazy)
 * 
 * CONCEPT (Migration "THROUGH SERVICE"):
 * Les utilisateurs demandent un accès au système pendant la migration.
 * Chaque accès (read/write) peut déclencher une migration lazy de cet utilisateur.
 * ==> Migration progressive sans batch worker séparé
 * 
 * COMMENT CA MARCHE:
 * 1. ShardRouter.findUser(email) effectue:
 *    - Read sur newShard
 *    - Si pas trouvé: read sur oldShard
 *    - Si trouvé sur oldShard: migrate lazy (copy + archive)
 * 
 * 2. Ce service (MigrationThroughService) est responsable de:
 *    - Coordonner les lectures intelligentes
 *    - Logger les migrations
 *    - Fournir les stats de progression
 * 
 * AVANTAGES:
 *   ✓ Zéro downtime: pas de batch lourd, opérations parallèles à l'activité
 *   ✓ Distribution uniforme: migrations réparties au fil des lectures
 *   ✓ "Free" migration: profite du traffic existant
 * 
 * DÉSAVANTAGES:
 *   ⚠️  Lent si peu d'accès: données rarement lues ne migrent pas
 *   ⚠️  Imprévisible: durée inconnue avant migration complète
 * 
 * SOLUTION: Combiner avec MigrationWorkerService (batch pour données froides)
 * 
 * @see MigrationWorkerService Pour batch migration des données non accédées
 * @see ShardRouter.findUser() Où l'implémentation réelle de lazy migration
 */
class MigrationThroughService
{
    /**
     * Routeur de sharding (contient la logique de two-ring)
     * 
     * @var ShardRouter
     */
    private ShardRouter $shardRouter;

    /**
     * Logger pour suivre progression et erreurs
     * 
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Statistiques de cette session de migration
     * 
     * Accumule:
     *   usersLazyMigrated: nombre d'utilisateurs migrés lazily
     *   usersRead: nombre total de lectures (incluant pas migrés)
     *   migrationErrors: nombre d'erreurs lors migrations
     * 
     * @var array
     */
    private array $stats = [
        'usersLazyMigrated' => 0,
        'usersRead' => 0,
        'migrationErrors' => 0,
    ];

    /**
     * Initialise le service
     * 
     * @param ShardRouter $shardRouter Routeur avec two-ring support
     * @param LoggerInterface|null $logger Logger optionnel (debug/stats)
     */
    public function __construct(ShardRouter $shardRouter, ?LoggerInterface $logger = null)
    {
        $this->shardRouter = $shardRouter;
        $this->logger = $logger;
    }

    /**
     * Récupère un utilisateur (avec migration lazy si nécessaire)
     * 
     * Wrapper autour de ShardRouter.findUser() pour logging et stats.
     * La migration réelle est effectuée par ShardRouter.
     * 
     * @param string $email Email de l'utilisateur
     * @return array|null Données utilisateur ou null
     * 
     * @throws ShardException Si erreur sharding
     */
    public function findUserWithMigration(string $email): ?array
    {
        $this->stats['usersRead']++;

        try {
            $user = $this->shardRouter->findUser($email);

            if ($user !== null) {
                $this->logger?->debug('User found and potentially migrated', [
                    'email' => $email,
                    'totalReads' => $this->stats['usersRead'],
                ]);
                $this->stats['usersLazyMigrated']++;
            }

            return $user;
        } catch (\Exception $exception) {
            $this->stats['migrationErrors']++;

            $this->logger?->error('Error during user find with migration', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            throw new ShardException(
                \sprintf('Failed to find user %s: %s', $email, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Récupère les statistiques de migration de cette session
     * 
     * @return array Dictionnaire avec clés:
     *   - usersLazyMigrated: nombre migrés via lazy reads
     *   - usersRead: nombre total de lectures
     *   - migrationErrors: nombre d'erreurs
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Réinitialise les statistiques (pour démarrer un nouveau cycle)
     * 
     * @return void
     */
    public function resetStats(): void
    {
        $this->stats = [
            'usersLazyMigrated' => 0,
            'usersRead' => 0,
            'migrationErrors' => 0,
        ];
    }

    /**
     * Retourne un rapport d'avancement de migration
     * 
     * Utile pour monitoring et alertes.
     * 
     * @return string Rapport formaté
     */
    public function getMigrationReport(): string
    {
        $report = \sprintf(
            "Migration Report (Through Service):\n" .
            "  Users lazy-migrated: %d\n" .
            "  Total reads processed: %d\n" .
            "  Migration errors: %d\n",
            $this->stats['usersLazyMigrated'],
            $this->stats['usersRead'],
            $this->stats['migrationErrors']
        );

        $migrationRate = ($this->stats['usersRead'] > 0)
            ? round(($this->stats['usersLazyMigrated'] / $this->stats['usersRead']) * 100, 2)
            : 0;

        $report .= \sprintf("  Migration rate: %.2f%%\n", $migrationRate);

        return $report;
    }
}
