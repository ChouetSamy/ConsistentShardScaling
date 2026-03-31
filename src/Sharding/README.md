# 🏗️ Architecture du Sharding avec Consistent Hashing

## Vue d'ensemble

Ce système implémente un **sharding horizontal cohérent** (consistent hashing) avec support des **migrations lazy** (au fil de l'eau).

**Caractéristiques principales:**
- ✅ Consistent Hashing (minimal redistribution lors scale)
- ✅ Two-Ring Pattern (oldRing + newRing pendant migration)
- ✅ Migration Lazy (données déplacées lors accès)
- ✅ Migration Batch Worker (migration proactive complète)
- ✅ PDO Prepared Statements (sécurité)
- ✅ Gestion erreurs et logging
- ✅ Code lisible et maintenable

---

## 📂 Structure des fichiers

```
src/Sharding/
├── HashRing.php                    # Consistent hashing ring
├── ShardRouter.php                 # Routeur avec two-ring
├── ShardException.php              # Exception personnalisée
│
├── config/
│   ├── ShardConfig.php            # Snapshot de configuration
│   └── ShardConfigManager.php      # Gestion des deux rings
│
├── storage/
│   ├── ShardDefinition.php        # Paramètres connexion shard
│   └── ShardConnectionManager.php # Pool de connexions PDO
│
└── migration/
    ├── MigrationThroughService.php # Migration lazy (during reads)
    └── MigrationWorkerService.php  # Migration batch (proactive)
```

---

## 🔄 Concepts clés

### Consistent Hashing

**Problème sans consistent hashing:**
- Ajouter 1 shard (3→4) : 75% des données redistribuées ❌
- Impossible à l'échelle

**Solution consistent hashing:**
- Ajouter 1 shard (3→4) : seulement 25% redistribuées ✅
- Scalable horizontalement

**Mécanisme:**
1. Chaque shard = 150 virtual nodes sur un ring
2. Pour une clé (email):
   - Hash déterministe → position sur le ring
   - Cherche premier vNode ≥ position
   - Retourne le shard
3. Même clé = toujours le même shard

### Two-Ring Pattern

Pendant migration (v1.0 → v1.1):

```
┌─────────────────────┐
│    Application      │
└──────────┬──────────┘
           │
      ┌────▼──────┐
      │ShardRouter│
      └┬──────┬───┘
       │      │
    ┌──▼─┐  ┌─▼──┐
    │Old │  │New │
    │Ring│  │Ring│
    │v1.0│  │v1.1│
    └─┬──┘  └──┬─┘
      │        │
      └────┬───┘
           │
    ┌──────▼──────┐
    │ PostgreSQL  │
    │  Shards     │
    └─────────────┘
```

**Avantages:**
- oldRing reste accessible (source données)
- newRing reçoit les nouvelles insertions
- Données migrées progressivement via reads

### Migration Lazy (Through Service)

Pattern: **Lors du READ d'un utilisateur**

```
findUser(email):
  ├─ newShard = newRing.getShard(email)
  ├─ Try: SELECT FROM newShard
  ├─ Found? → RETURN
  └─ Not found:
     ├─ oldShard = oldRing.getShard(email)
     ├─ Try: SELECT FROM oldShard
     ├─ Found? → [MIGRATE LAZY]
     │  ├─ INSERT INTO newShard (CONFLICT DO NOTHING)
     │  ├─ DELETE FROM oldShard
     │  └─ RETURN user
     └─ Not found? → RETURN null
```

**Avantages:**
- ✅ Zéro downtime
- ✅ Migration au fil de l'eau
- ✅ "Gratuit" proche du traffic existant

**Désavantages:**
- ⚠️ Lent si peu d'accès

### Migration Batch (Worker Service)

Pattern: **Proactive batch migration**

```
MigrationWorkerService.migrate():
  ├─ COUNT users FROM oldShard
  ├─ For each batch (500 users):
  │  ├─ FETCH batch FROM oldShard
  │  └─ For each user:
  │     ├─ INSERT INTO newShard (CONFLICT DO NOTHING)
  │     ├─ DELETE FROM oldShard
  │     └─ Log success/failure
  └─ Return stats
```

**Avantages:**
- ✅ Rapide et complète
- ✅ Prévisible (deadline connu)
- ✅ Capture données froides

**Désavantages:**
- ⚠️ Impact I/O
- ⚠️ Locking risque

---

## 🚀 Guide d'utilisation

### 1. Setup initial (sans migration)

```php
// config/services.yaml ou équivalent

use App\Sharding\{ShardDefinition, ShardConfig, ShardException};
use App\Sharding\{HashRing, ShardRouter, ShardConnectionManager};
use App\Sharding\Config\ShardConfigManager;

// Définir les shards
$shards = [
    new ShardDefinition('shard_1', 'db1.example.com', 5432, 'sharding_db', 'app_user', 'password'),
    new ShardDefinition('shard_2', 'db2.example.com', 5432, 'sharding_db', 'app_user', 'password'),
    new ShardDefinition('shard_3', 'db3.example.com', 5432, 'sharding_db', 'app_user', 'password'),
];

// Config v1.0
$configV1 = new ShardConfig(1, $shards, 150);

// Manager
$configManager = new ShardConfigManager();
$configManager->initializeWithCurrentConfig($configV1);

// Connexions
$connectionManager = new ShardConnectionManager($configV1);

// Router
$router = new ShardRouter($configManager, $connectionManager, $logger);

// Usage
$router->insertUser('user@example.com', 'John Doe');
$user = $router->findUser('user@example.com');
```

### 2. Migration (two-ring)

```php
// Ajouter 1 shard (v1.0 → v1.1)
$newShards = [
    new ShardDefinition('shard_1', 'db1.example.com', 5432, 'sharding_db_v2', 'app_user', 'password'),
    new ShardDefinition('shard_2', 'db2.example.com', 5432, 'sharding_db_v2', 'app_user', 'password'),
    new ShardDefinition('shard_3', 'db3.example.com', 5432, 'sharding_db_v2', 'app_user', 'password'),
    new ShardDefinition('shard_4', 'db4.example.com', 5432, 'sharding_db_v2', 'app_user', 'password'),
];

$configV2 = new ShardConfig(2, $newShards, 150);

// Manager: init avec DEUX configs
$configManager = new ShardConfigManager();
$configManager->initializeWithMigration($configV2, $configV1);

// Connexions vers new shards
$connectionManager = new ShardConnectionManager($configV2);

// Router: automatiquement two-ring
$router = new ShardRouter($configManager, $connectionManager, $logger);

// Toutes les lectures effectueront lazy migration
$user = $router->findUser('user@example.com');  // Migré si trouvé sur oldRing

// Nouvelles insertions vont directement sur newRing
$router->insertUser('new@example.com', 'Jane Doe');
```

### 3. Migration Lazy (Through Service)

```php
$throughService = new MigrationThroughService($router, $logger);

// Traite les utilisateurs au fil des accès
while (true) {
    $email = getNextEmailFromQueue(); // Source: queue/CSV/API
    try {
        $user = $throughService->findUserWithMigration($email);
        // Utilisateur migré automatiquement si trouvé sur oldRing
    } catch (ShardException $exception) {
        echo "Error: " . $exception->getMessage();
    }
}

// Stats
echo $throughService->getMigrationReport();
// Output:
// Migration Report (Through Service):
//   Users lazy-migrated: 15000
//   Total reads processed: 20000
//   Migration errors: 3
//   Migration rate: 75.00%
```

### 4. Migration Batch (Worker)

```php
// Migrer shard_1 de l'ancienne config vers la nouvelle
$worker = new MigrationWorkerService(
    connectionManager: $connectionManager,
    oldShardId: 'shard_1_old',
    newShardId: 'shard_1_new',
    logger: $logger,
    batchSize: 500
);

try {
    $stats = $worker->migrate();
    echo $worker->getMigrationReport();
} catch (ShardException $exception) {
    echo "Batch migration failed: " . $exception->getMessage();
}
```

---

## 🔒 Sécurité

### Requêtes Prepared Statements

✅ Toutes les requêtes utilisent PDO prepared statements:

```php
// ✓ CORRECT (paramètre lié)
$statement = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$statement->execute([':email' => $email]);

// ✗ DANGEREUX (injection SQL)
$query = "SELECT * FROM users WHERE email = '$email'";
$statement = $pdo->query($query);
```

### Gestion credentials

⚠️ **NE PAS** hardcoder les mots de passe:

```php
// ✓ CORRECT
$password = getenv('DB_PASSWORD');  // Charge depuis env var

// ✗ DANGEREUX
$password = 'my_password';  // Hardcoded (git expose!)
```

### Erreurs

Tous les PDOException propagés avec logging:

```php
try {
    $pdo->execute();
} catch (PDOException $exception) {
    $logger->error('DB error', ['error' => $exception->getMessage()]);
    throw new ShardException(...);  // Réwrap pour abstraction
}
```

---

## 💾 Schéma des bases de données

### Table users (chaque shard)

```sql
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_users_email ON users(email);
```

### Version des bases

Pour isolement complet, créer une DB par version:

```sql
-- Old shards (v1.0)
CREATE DATABASE sharding_db_v1;

-- New shards (v1.1)
CREATE DATABASE sharding_db_v2;
```

---

## 📊 Métriques et monitoring

### ShardRouter logs

```
INFO: User found in newShard (email: user@example.com, shardId: shard_2)
INFO: User found in oldShard (email: old@example.com, oldShardId: shard_1, newShardId: shard_2)
INFO: Lazy migration completed (email: old@example.com, oldShardId: shard_1, newShardId: shard_2)
ERROR: Error fetching user from shard (shardId: shard_1, email: test@example.com, error: Connection timeout)
```

### MigrationThroughService stats

```
$stats = $throughService->getStats();
// [
//    'usersLazyMigrated' => 15000,
//    'usersRead' => 20000,
//    'migrationErrors' => 3
// ]
```

### MigrationWorkerService stats

```
$stats = $worker->getStats();
// [
//    'totalUsers' => 50000,
//    'usersMigrated' => 49996,
//    'usersFailed' => 4,
//    'usersSkipped' => 0,
//    'durationSeconds' => 142.5
// ]
```

---

## 🐛 Troubleshooting

### "Shard not found"

```
ShardException: Shard "shard_5" not found. Available shards: shard_1, shard_2, shard_3
```

**Cause:** ShardDefinition n'existe pas dans la config

**Solution:** Vérifier liste des shards dans ShardConfig

### "User not found after migration"

Possible causes:
1. Données pas encore migrées (lazy migration pending)
2. Données supprimées volontairement
3. Problème réseau (oldShard inaccessible)

**Solution:** Vérifier logs, relancer worker batch si nécessaire

### INSERT CONFLICT DO NOTHING silencieux

Pattern normal si user existe déjà dans newShard (migration partielle)

```php
// Idempotent: appeler plusieurs fois = safe
$router->insertUser('user@example', 'Name');
$router->insertUser('user@example', 'Name');  // No error, just ignored
```

---

## 🚀 Performance

### Consistent Hashing complexity

| Operation | Complexity | Notes |
|-----------|-----------|--------|
| `addShard(id)` | O(vNodes) | 150 insertions |
| `getShard(key)` | O(n) | Peut être O(log n) avec binary search |
| `findUser()` | O(1) to O(n) | Dépend du size du ring |

### Optimisations futures

- [ ] Binary search pour getShard() (O(log n))
- [ ] Pool réel de connexions (> 1 par shard)
- [ ] Connexion pooling avec persistent connections
- [ ] Caching des rings (LRU cache)
- [ ] Monitoring temps réel (Prometheus metrics)
- [ ] Circuit breaker pour shards down

---

## 📖 Référence complète

### Classes principales

| Classe | Responsabilité |
|--------|-----------------|
| `HashRing` | Consistent hashing (position → shard) |
| `ShardRouter` | Routage avec two-ring (find/insert) |
| `ShardConfigManager` | Gestion oldRing + newRing |
| `ShardConnectionManager` | Pool connexions PDO |
| `ShardConfig` | Snapshot configuration |
| `ShardDefinition` | Paramètres connexion |
| `MigrationThroughService` | Migration lazy (reads) |
| `MigrationWorkerService` | Migration batch (proactive) |
| `ShardException` | Exception métier |

### Fichiers supplémentaires

- `doc/systemToScaleArchi.md`: Diagrammes d'architecture
- README.md (ce fichier): Guide complet

---

## 📝 Exemple complet d'application

```php
<?php
namespace App\Consumer;

use App\Sharding\ShardRouter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserConsumerCommand extends Command
{
    public function __construct(private ShardRouter $shardRouter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Phase 1: Lazy migration during application reads
        $users = $this->getUsersFromAPI();
        
        foreach ($users as $userEmail => $userData) {
            try {
                // Automatiquement migré si sur oldShard
                $existingUser = $this->shardRouter->findUser($userEmail);
                
                if (!$existingUser) {
                    // Insertions vont directement sur newShard
                    $this->shardRouter->insertUser($userEmail, $userData['name']);
                    $output->writeln("Created: $userEmail");
                } else {
                    $output->writeln("Exists: $userEmail (migrated)");
                }
            } catch (Exception $exception) {
                $output->writeln("Error: " . $exception->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
```

---

**État du projet:** ✅ Production-ready
**Dernière mise à jour:** Mars 2026
**Auteur:** Votre équipe engineering
