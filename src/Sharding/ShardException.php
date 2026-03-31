<?php

namespace App\Sharding;

/**
 * Exception levée lors d'erreurs liées au sharding
 * 
 * Utilisée pour les erreurs:
 *   - Résolution de shard échouée
 *   - Ring non initialisé
 *   - Migration lazy échouée
 *   - Accès à shard indisponible
 * 
 * Permet de distinguer les erreurs métier (sharding) des erreurs PDO brutes.
 */
class ShardException extends \RuntimeException
{
}
