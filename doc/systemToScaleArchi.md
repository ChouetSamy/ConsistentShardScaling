🔥 4. Liste des systèmes distribués (version archi terrain)

Je vais te faire une liste utile
avec : usage + difficulté + coût + compatibilité

🗄️ 4.1 SQL distribué
🔹 PostgreSQL + sharding manuel
💰 coût : faible
⚙️ complexité : élevée (ce que tu fais)
✔️ contrôle total
❌ pas automatique

👉 TON PROJET ACTUEL

🔹 Citus
PostgreSQL distribué
💰 moyen
⚙️ facile
✔️ compatible SQL

👉 meilleur compromis

🔹 CockroachDB
SQL distribué natif
💰 moyen/élevé
⚙️ facile
✔️ tolérance aux pannes

👉 remplace PostgreSQL en distribué

🔹 Google Spanner
ultra distribué
💰 très cher
⚙️ complexe
✔️ global scale

👉 grosse boîte uniquement

🌐 4.2 NoSQL distribué
🔹 Apache Cassandra
💰 moyen
⚙️ complexe
✔️ scalable
✔️ consistent hashing natif

👉 proche de ce que tu fais

🔹 Amazon DynamoDB
💰 pay-as-you-go
⚙️ facile
✔️ managé

👉 version cloud de Dynamo

🔹 MongoDB
💰 moyen
⚙️ facile
✔️ sharding intégré

👉 simple pour commencer

🔹 Redis
💰 faible → moyen
⚙️ facile
✔️ rapide
❌ pas persistant par défaut

👉 cache / queue / temps réel

🧩 4.3 Middleware (très important pour archi)
🔹 Vitess
💰 moyen
⚙️ complexe
✔️ sharding automatique

👉 utilisé par YouTube

🔹 ProxySQL
💰 faible
⚙️ moyen
✔️ routing SQL


🔹 PgBouncer
💰 faible
⚙️ facile
✔️ pool connexions

📊 4.4 Streaming / queue (clé pour ton projet)
🔹 Apache Kafka
💰 moyen
⚙️ complexe
✔️ event-driven

👉 migration async / logs

🔹 RabbitMQ
💰 faible
⚙️ moyen
✔️ queue classique

🧠 5. Lecture architecte

🎯 Stack réaliste
PostgreSQL + Citus
Redis
Kafka (optionnel)
Symfony / API

🎯 Stack simple (MVP)
PostgreSQL
Redis
Symfony

🎯 Stack avancée
CockroachDB ou Cassandra
Kafka
Kubernetes

## 📐 ARCHITECTURE DU SHARDING AVEC MIGRATION LAZY

### 🏗️ Composants système

```
┌─────────────────────────────────────────────────────────────────┐
│                    Client Application                           │
│                   (Symfony Service)                             │
└─────────────┬───────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    ShardRouter                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  - findUser(email) : routage two-ring avec migration    │  │
│  │  - insertUser(email, name) : insertion avec newRing    │  │
│  │  - getShardId(key) : résolution de shard               │  │
│  └──────────────────────────────────────────────────────────┘  │
└──┬──────────────┬──────────────┬──────────────────────────────┘
   │              │              │
   ▼              ▼              ▼
┌─────────┐  ┌─────────┐    ┌─────────────────┐
│ oldRing │  │ newRing │    │ShardConnManager │
│ (v1.0)  │  │ (v1.1)  │    │   + PDO Pool    │
│ 3 shares│  │ 4 shares│    │                 │
└────┬────┘  └────┬────┘    └────────┬────────┘
     │            │                  │
     └────────┬───┴──────────┬───────┘
              │              │
              ▼              ▼
         ┌─────────────────────────────┐
         │   PostgreSQL Shards         │
         │ ┌──────┬──────┬──────┐      │
         │ │Shard1│Shard2│Shard3│      │
         │ └──────┴──────┴──────┘      │
         │         (v1.0)              │
         └─────────────────────────────┘

         ┌─────────────────────────────┐
         │   PostgreSQL Shards         │
         │ ┌──────┬──────┬──────┬──┐   │
         │ │Shard1│Shard2│Shard3│S4│   │
         │ └──────┴──────┴──────┴──┘   │
         │         (v1.1)              │
         └─────────────────────────────┘
```

### 📊 Pattern Two-Ring (oldRing et newRing)

Pendant une migration, le système maintient DEUX rings en parallèle :
- **oldRing** : config précédente (ex: 3 shards)
- **newRing** : nouvelle config avec plus de shards (ex: 4 shards)

Cela permet une migration LAZY (au fil de l'eau) : les données se déplacent lors du premier accès.

### 🔄 Séquence de findUser(email) avec lazy migration

```
Client                ShardRouter              oldRing/newRing         ShardDB
  │                       │                         │                    │
  ├──findUser(email)────>│                         │                    │
  │                       │                         │                    │
  │                    ┌─────────────────────────┐ │                    │
  │                    │ 1. Calculate newShard   │ │                    │
  │                    │    newShard = hash(email)                       │
  │                    └─────────────────────────┘ │                    │
  │                       │                         │                    │
  │                       ├─hasNewRing?────────────>│                    │
  │                       │<─────true──────────────│                    │
  │                       │                         │                    │
  │                    ┌─────────────────────────┐ │                    │
  │                    │ 2. Try read on newShard │ │                    │
  │                    └──────┬──────────────────┘ │                    │
  │                           ├────getConnection──>│                    │
  │                           │<──PDO──────────────│                    │
  │                       │   ├────SELECT user────────────────────────>│
  │                       │   │                         │<──user (found)──│
  │                       │   │                         │                │
  │                    ┌─────────────────────────────────────────────┐  │
  │                    │ 3a. User FOUND in newShard → return user    │  │
  │                    └──────────────┬──────────────────────────────┘  │
  │<──user────────────user────────────│                                │
  │                                   │                                │
  │                                [Alternative: NOT found]            │
  │                                   │                                │
  │                    ┌─────────────────────────────────────────────┐  │
  │                    │ 3b. User NOT found → try oldShard (if exists)│  │
  │                    └──────────────┬──────────────────────────────┘  │
  │                       │                         │                    │
  │                       ├────Calculate oldShard──>│                    │
  │                       │<─oldShard by oldRing───│                    │
  │                       │                         │                    │
  │                       ├────SELECT from oldShard──────────────────>  │
  │                       │<──user (found)────────────────────────────  │
  │                       │                         │                    │
  │                    ┌─────────────────────────────────────────────┐  │
  │                    │ 4. oldShard ≠ newShard ?                   │  │
  │                    │    YES → Lazy migrate:                      │  │
  │                    │    - INSERT into newShard (CONFLICT IGNORE)│  │
  │                    │    - ARCHIVE/DELETE from oldShard          │  │
  │                    └──────────────┬──────────────────────────────┘  │
  │                       │           │                                │
  │                       ├──────────>│──INSERT INTO newShard────────>  │
  │                       │           │                                │
  │                       │           │<──INSERT OK──────────────────  │
  │                       │                         │                    │
  │                       ├──DELETE from oldShard──────────────────>   │
  │                       │<──DELETE OK──────────────────────────────   │
  │                       │                         │                    │
  │<──user────────────user────────────│                                │
```

### 🔄 Séquence de insertUser(email, name)

```
Client              ShardRouter         newRing        ShardDB
  │                     │                │              │
  ├─insertUser────────>│                │              │
  │                     │                │              │
  │                  ┌─────────────────┐│              │
  │                  │ 1. Calculate ID │                │
  │                  │ shardId = newRing│              │
  │                  │              .getShard(email)   │
  │                  └────────┬────────┘│              │
  │                     │     │                        │
  │                     ├────────getConnection(s)────>│
  │                     │<─────PDO─────────────────────│
  │                     │     │                        │
  │                  ┌──────────────────────────────┐  │
  │                  │ 2. INSERT prepared statement │  │
  │                  └──────────┬───────────────────┘  │
  │                     │  ├─INSERT INTO users───────>│
  │                     │  │ (email, name)           │
  │                     │  │<─OK────────────────────  │
  │                     │ [PDO exception handled]     │
  │<──void──────────────│                             │
```

### 🎯 Algorithme findUser - Flowchart détaillé

```
START: findUser(email)
│
├─[1]─> newShard = newRing.getShard(email)
│         │
│         ├─> Hash email avec MD5
│         └─> Find position sur le ring
│
└─[2]─> pdo = connectionManager.getConnection(newShard)
         │
         ├─[2a]─────────── Prepared statement: SELECT * FROM users WHERE email = ?
         │                  │
         │                  └─> Execute avec email en paramètre
         │
         └─[2b]─> HAS result?
                    │
                    ├─YES─> RETURN user (migration done, ou première insertion)
                    │
                    └─NO──> oldShard = oldRing.getShard(email)
                            │
                            ├─[if oldRing exists]
                            │
                            ├─[3]─> pdo = connectionManager.getConnection(oldShard)
                            │         │
                            │         └─> SELECT * FROM users WHERE email = ?
                            │
                            └─[4]─> HAS result?
                                     │
                                     ├─YES–> IS oldShard ≠ newShard?
                                     │       │
                                     │       ├─YES–> [LAZY MIGRATION]
                                     │       │       │
                                     │       │       ├─ INSERT INTO newShard
                                     │       │       │  (INSERT CONFLICT DO NOTHING)
                                     │       │       │
                                     │       │       ├─ DELETE FROM oldShard
                                     │       │       │
                                     │       │       └─ RETURN user
                                     │       │
                                     │       └─NO–> RETURN user (same shard)
                                     │
                                     └─NO–> RETURN null (user not found)

END: return user | null
```

### 🛡️ Gestion des erreurs

- **Shard indisponible** : Exception PDO catchée, log erreur
- **Conflit insertion (INSERT ON CONFLICT)** : Ignore si user existe déjà (données dupliquées possible en migration)
- **Shard non trouvé** : Exception lancée (configuration invalide)
- **Email vide** : Validation avant routage

---

