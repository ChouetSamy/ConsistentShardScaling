<?php

namespace App\Sharding\Config;

use App\Sharding\HashRing;
use App\Sharding\Config\ShardConfig;

/**
 * Gestionnaire de configuration des shards avec support des migrations
 * 
 * Ce service maintient DEUX versions du HashRing en parallèle pour permettre
 * les migrations lazy. Pendant une migration, les données se déplacent du newRing
 * vers l'oldRing lors du premier accès (lecture).
 * 
 * Exemple:
 *   - oldRing: version v1.0 avec 3 shards
 *   - newRing: version v1.1 avec 4 shards
 * 
 * Quand findUser() cherche un utilisateur:
 *   1. Calcule newShard via newRing (destination après migration)
 *   2. Tente une lecture sur newShard
 *   3. Si trouvé → migration déjà faite, return
 *   4. Si non trouvé et oldRing existe → cherche sur oldShard (ancien emplacement)
 *   5. Si trouvé sur oldShard → effectue la migration lazy (copy + delete)
 * 
 * Avantages du pattern two-ring:
 *   ✓ Zéro downtime: données migrées progressivement
 *   ✓ Pas de batch: migration au fil de l'eau (during reads)
 *   ✓ Tolérance aux pannes: les deux rings restent accessibles
 *   ✓ Atomicité: chaque migration est atomique (atomicity per user)
 */
class ShardConfigManager
{
    /**
     * HashRing courant (destination pour nouvelles insertions et lectures)
     * 
     * @var HashRing|null
     */
    private ?HashRing $newRing = null;

    /**
     * HashRing ancien (source lors des migrations lazy)
     * 
     * Null si pas de migration en cours.
     * Reste accessible pendant la migration pour retrouver les données anciennes.
     * 
     * @var HashRing|null
     */
    private ?HashRing $oldRing = null;

    /**
     * Initialise le gestionnaire avec une seule configuration (pas de migration)
     * 
     * @param ShardConfig $currentConfig La configuration actuelle des shards
     */
    public function initializeWithCurrentConfig(ShardConfig $currentConfig): void
    {
        $this->newRing = $this->createHashRing($currentConfig);
        $this->oldRing = null;
    }

    /**
     * Initialise le gestionnaire avec deux configurations (en migration)
     * 
     * La migration commence quand cette méthode est appelée.
     * Pendant la migration:
     *   - Les LECTURES utilisent newRing en priorité, puis oldRing si pas trouvé
     *   - Les INSERTIONS utilisent UNIQUEMENT newRing
     * 
     * @param ShardConfig $currentConfig La nouvelle configuration (destination)
     * @param ShardConfig $previousConfig L'ancienne configuration (source)
     * 
     * @throws \InvalidArgumentException Si les deux configs ont la même version
     */
    public function initializeWithMigration(ShardConfig $currentConfig, ShardConfig $previousConfig): void
    {
        if ($currentConfig->version <= $previousConfig->version) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'currentConfig version (%d) must be greater than previousConfig version (%d)',
                    $currentConfig->version,
                    $previousConfig->version
                )
            );
        }

        $this->newRing = $this->createHashRing($currentConfig);
        $this->oldRing = $this->createHashRing($previousConfig);
    }

    /**
     * Construit un HashRing à partir d'une configuration
     * 
     * Crée tous les virtual nodes pour chaque shard du ring.
     * 
     * @param ShardConfig $config Configuration avec la liste des shards
     * @return HashRing Le ring initialisé et ordonné
     */
    private function createHashRing(ShardConfig $config): HashRing
    {
        $hashRing = new HashRing();

        foreach ($config->shards as $shardDefinition) {
            $hashRing->addShard($shardDefinition->id);
        }

        return $hashRing;
    }

    /**
     * Récupère le HashRing courant (pour les nouvelles insertions)
     * 
     * @return HashRing Le ring de destination (newRing)
     */
    public function getNewRing(): HashRing
    {
        if ($this->newRing === null) {
            throw new \RuntimeException('ShardConfigManager not initialized. Call initialize* method first.');
        }

        return $this->newRing;
    }

    /**
     * Récupère le HashRing ancien (pour les lectures avec fallback)
     * 
     * Retourne null si pas de migration en cours.
     * 
     * @return HashRing|null Le ring source (oldRing) ou null
     */
    public function getOldRing(): ?HashRing
    {
        return $this->oldRing;
    }

    /**
     * Vérifie si une migration est en cours
     * 
     * @return bool true si oldRing existe (migration active), false sinon
     */
    public function isMigrating(): bool
    {
        return $this->oldRing !== null;
    }

    /**
     * Finalise la migration en supprimant l'ancienne configuration
     * 
     * À appeler quand TOUS les utilisateurs ont migré vers newRing.
     * Après cet appel, oldRing devient inaccessible.
     * 
     * ⚠️  À utiliser avec prudence: vérifier que oldRing ne contient plus de données
     * ou disposer d'une sauvegarde avant d'appeler cette méthode.
     */
    public function completeMigration(): void
    {
        $this->oldRing = null;
    }
}
