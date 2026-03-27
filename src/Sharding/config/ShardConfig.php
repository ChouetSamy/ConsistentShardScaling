<?php
namespace App\Sharding;

/**
 * Snapshot du système de shards
 * Contient uniquement de la DATA (pas de logique)
 */
class ShardConfig
{
    public int $version;

    /** @var ShardDefinition[] */
    public array $shards;

    public int $virtualNodes;

    public function __construct(int $version, array $shards, int $virtualNodes)
    {
        $this->version = $version;
        $this->shards = $shards;
        $this->virtualNodes = $virtualNodes;
    }
}