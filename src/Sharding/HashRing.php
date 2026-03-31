<?php

namespace App\Sharding;

/**
 * Implémentation du Consistent Hashing Ring
 * 
 * CONCEPT:
 * - Mappe les clés (emails) de manière déterministe vers des shards
 * - Utilise les "virtual nodes" pour meilleure distribution des données
 * - Minimise la redistribution lors ajout/suppression de shards
 * 
 * ALGORITHME:
 * 1. Chaque shard reçoit N virtual nodes (ex: 150)
 * 2. Chaque vNode a une position sur le ring (calculée par hash)
 * 3. Pour trouver le shard d'une clé:
 *    a. Hash la clé (déterministe, range: 0 à 2^32-1)
 *    b. Cherche le premier vNode ≥ position de la clé
 *    c. Return le shard de ce vNode
 * 4. Si pas trouvé (wraparound), retourne le premier vNode du ring
 * 
 * AVANTAGES:
 *   ✓ Ajouter/retirer un shard:
 *       - Seulement N/(N+1) des données redistribuées (N = nb shards)
 *       - Ex: 3 shards → 4 shards = 25% redistribution
 *       - Sans vNode: 100% redistribution!
 * 
 *   ✓ Distribution équilibrée:
 *       - chaque shard ≈ même nb de vNodes
 *       - donc ≈ même volume de données
 * 
 *   ✓ Déterministe:
 *       - Même clé = Toujours le même shard
 *       - Pas de lookup en base (pas de table sharding_key)
 * 
 * EXEMPLE:
 * Ring avec 2 shards, 3 vNodes chacun:
 * 
 *   Positions
 *   0%    Shard1#0=100
 *   ↓     Shard2#1=200
 *   |     Shard1#1=300
 *   |     Shard2#2=400
 *   |     Shard1#2=500
 *   |     Shard2#0=600
 *   ↓     (wrap)
 *   100%
 * 
 *   Cherche key avec hash=250:
 *     - Position 250 < 300 (Shard1#1)
 *     - Donc key → Shard1 ✓
 * 
 *   Cherche key avec hash=550:
 *     - Position 550 < 600 (Shard2#0)
 *     - Donc key → Shard2 ✓
 * 
 *   Cherche key avec hash=700:
 *     - Position 700 > tous les vNodes
 *     - Wraparound: retourne Shard1#0
 *     - Donc key → Shard1 ✓
 * 
 * VIRTUAL NODES:
 * - Nombre de copies de chaque shard sur le ring
 * - Plus de vNodes = meilleure distribution + plus d'espace en mémoire
 * - Recommandé: 150 en production (balance perf/distribution)
 * 
 * @see https://en.wikipedia.org/wiki/Consistent_hashing pour plus d'infos
 */
class HashRing
{
    /**
     * Ring (anneau des positions)
     * 
     * Structure: [position => shardId]
     *   position: index numérique de 0 à 2^32-1 (résultat du hash)
     *   shardId: identifiant du shard (ex: "shard_1", "shard_2")
     * 
     * Les positions sont TRIÉES par valeur croissante (ksort) pour:
     *   - Parcours séquentiel lors recherche du shard
     *   - Algorithme de wraparound efficace
     * 
     * @var array<int, string>
     */
    private array $ring = [];

    /**
     * Nombre de virtual nodes par shard
     * 
     * Chaque shard a virtualNodes copies sur le ring.
     * Plus de vNodes = meilleure distribution mais plus d'espace mémoire.
     * 
     * Valeurs recommandées:
     *   - Développement: 3-10
     *   - Production: 100-200 (généralement 150)
     * 
     * @var int
     */
    private int $virtualNodes = 150;

    /**
     * Ajoute un shard au ring avec ses virtual nodes
     * 
     * ÉTAPES:
     * 1. Crée virtualNodes copies du shard
     * 2. Chaque copie:
     *    a. Format: "shardId#i" (ex: "shard_1#0", "shard_1#1")
     *    b. Hash ce string pour obtenir position
     *    c. Add (position => shardId) au ring
     * 3. Trie le ring par position croissante
     * 
     * IMPORTANT:
     * - Le tri est VITAL pour l'algorithme de recherche
     * - Appellé lors initialization ou dynamiquement lors rescale
     * 
     * @param string $shardId Identifiant unique du shard
     * @return void
     * 
     * @throws InvalidArgumentException Si shardId vide
     */
    public function addShard(string $shardId): void
    {
        if (empty(trim($shardId))) {
            throw new \InvalidArgumentException('Shard ID cannot be empty');
        }

        // ÉTAPE 1: Crée virtual nodes pour ce shard
        for ($increment = 0; $increment < $this->virtualNodes; $increment++) {
            $virtualNodeKey = $shardId . '#' . $increment;  // Format: "shard_1#0", "shard_1#1", ...
            $position = $this->hash($virtualNodeKey);      // Position déterministe sur le ring
            $this->ring[$position] = $shardId;             // Mappe la position au shard
        }

        // ÉTAPE 2: Trie le ring par position (indispensable pour recherche)
        ksort($this->ring);
    }

    /**
     * Détermine le shard pour une clé (email, user_id, etc)
     * 
     * ALGORITHME:
     * 1. Hash la clé pour obtenir une position (0 à 2^32-1)
     * 2. Itère sur les positions du ring (triées)
     * 3. Cherche le premier vNode dont la position >= hash de la clé
     * 4. Return le shard de ce vNode
     * 5. Si aucun trouvé (pas de vNode >= position), wraparound au premier
     * 
     * COMPLEXITÉ:
     * - O(log N) avec binary search (optimisation possible)
     * - O(N) avec iteration linéaire (implémentation actuelle)
     * 
     * DÉTERMINISME:
     * - Même clé = Toujours le même shard
     * - Garanti tant que le ring ne change pas
     * 
     * @param string $key La clé à router (email, user_id, etc)
     * @return string L'ID du shard pour cette clé
     * 
     * @throws RuntimeException Si ring vide
     */
    public function getShard(string $key): string
    {
        if (empty($this->ring)) {
            throw new \RuntimeException('Hash ring is empty. No shards added.');
        }

        $position = $this->hash($key);

        // Parcourt les positions du ring en ordre croissant
        foreach ($this->ring as $nodePosition => $shardId) {
            if ($nodePosition >= $position) {
                return $shardId; // Trouvé : c'est le premier vNode >= notre position
            }
        }

        // Pas trouvé : wraparound au premier vNode du ring
        return reset($this->ring);
    }

    /**
     * Calcule le hash d'une chaîne (déterministe)
     * 
     * FONCTION:
     * - MD5 (32 caractères hexadécimaux)
     * - Prend les 8 premiers caractères
     * - Convertit en entier (base 16 → base 10)
     * - Résultat: 0 à 2^32-1 (4 bytes = 32 bits)
     * 
     * POURQUOI MD5?
     *   ✓ Déterministe (même input = même output toujours)
     *   ✓ Distribution uniforme (peu de collisions)
     *   ✓ Performance (vite même en PHP)
     *   ✓ Standard pour consistent hashing
     * 
     * SÉCURITÉ:
     *   ✗ MD5 ne doit PAS être utilisé pour mots de passe (trop faible)
     *   ✓ MD5 ok pour hashing distribué (pas sécurité, juste distribution)
     * 
     * EXEMPLE:
     *   hash("user@example.com")
     *     → MD5 = "abc123def456..."
     *     → Premiers 8 chars = "abc123de"
     *     → hexdec = 2882097630
     *     → Retourne: 2882097630
     * 
     * @param string $key Chaîne à hasher
     * @return int Position sur le ring (0 à 2^32-1)
     * 
     * @throws InvalidArgumentException Si clé vide
     */
    private function hash(string $key): int
    {
        if (empty(trim($key))) {
            throw new \InvalidArgumentException('Cannot hash empty key');
        }

        $md5Hash = md5($key);
        $position = hexdec(substr($md5Hash, 0, 8));

        return $position;
    }
}