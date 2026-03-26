<?php

namespace App\Sharding;

class HashRing
{
    private array $ring = [];        // position => shardId
    private int $virtualNodes = 10;   // nb de vNodes par shard

    public function addShard(string $shardId): void
    {
        // TODO : pour chaque vNode (0, 1, 2...),
        // calcule une position sur l'anneau
        // et stocke-la dans $this->ring
        // clé = position, valeur = shardId


    }

    public function getShard(string $key): string
    {
        // TODO : hash la clé, trouve la position
        // retourne le shardId du premier vNode >= position
        // (si aucun, prendre le premier de l'anneau = wrap around)
    }

    private function hash(string $key): int
    {
        // TODO : une ligne, cf. ce qu'on vient de voir
        return hexdec(substr(md5($key), 0, 8));
    }
}