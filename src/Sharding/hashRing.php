<?php
namespace App\Sharding;

/**
 * algorithm to create ring
 * create vNode, vNode is a position to take on the ring for a shard, it allow shard to have many position
 * thus ensure better repartition of data amongs shards.
 * hash algo create a position, it also find a position, since it's deterministic
 */
class HashRing
{
    private array $ring = [];//ensemble des positions des shards
    private int $virtualNodes = 150;//jusqu'à 150 en prod, la vNode est une position de plus sur le ring


    /**
     * add a shard to the ring, create as many positions on the ring as VNode 
     * @param string $shardId
     * @return void
     */
    public function addShard(string $shardId): void
    {
        //pour i plus petit que le nb de virtual node, i incrémente
        for ($i = 0; $i < $this->virtualNodes; $i++) {
            $key            = $shardId . '#' . $i;  // "shardA#0", "shardA#1"...
            $position       = $this->hash($key);     // position déterministe
            $this->ring[$position] = $shardId;       // clé=position, valeur=shard
        }
        ksort($this->ring); // tri par position croissante, indispensable
    }

    public function getShard(string $key): string
    {
        $position = $this->hash($key);

        // On cherche le premier vNode dont la position >= hash de la clé
        foreach ($this->ring as $nodePosition => $shardId) {
            if ($nodePosition >= $position) {
                return $shardId; // trouvé !
            }
        }

        // Wrap around : on est après le dernier vNode, on revient au début
        return reset($this->ring);
    }

    private function hash(string $key): int
    {
        return hexdec(substr(md5($key), 0, 8));
    }
}