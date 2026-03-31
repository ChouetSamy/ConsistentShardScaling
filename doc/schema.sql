-- ========== SCHEMA SHARDING DATABASE ==========
-- 
-- À exécuter sur chaque shard (PostgreSQL 12+)
-- 
-- Chaque shard contient la même structure (users table)
-- La partition est déterminée par consistent hashing (côté application)

-- ========== TABLE USERS ==========

CREATE TABLE IF NOT EXISTS users (
    -- Identifiant primaire (BIGSERIAL = auto-increment 64 bits)
    id BIGSERIAL PRIMARY KEY,

    -- Email unique (clé de sharding)
    -- Utilisé pour consistent hashing et recherche
    email VARCHAR(255) NOT NULL UNIQUE,

    -- Nom de l'utilisateur
    name VARCHAR(255) NOT NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Optionnel: colonne d'archivage soft-delete
    -- archived_at TIMESTAMP,
    
    -- Optionnel: colonne de version pour conflits
    -- version BIGINT DEFAULT 1
);

-- Historique de création
COMMENT ON TABLE users IS 'Table des utilisateurs (sharded horizontalement)';
COMMENT ON COLUMN users.email IS 'Email unique - clé de sharding';
COMMENT ON COLUMN users.created_at IS 'Timestamp de création (GMT)';
COMMENT ON COLUMN users.updated_at IS 'Timestamp dernière modification (GMT)';

-- ========== INDEXES ==========

-- Index sur email pour recherche (déjà implicite via UNIQUE, mais explicite ici)
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Index sur created_at pour range queries (audit/analytics)
CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at DESC);

-- Index sur updated_at pour synchronisation incrémentale
CREATE INDEX IF NOT EXISTS idx_users_updated_at ON users(updated_at DESC);

-- ========== FONCTION TRIGGER UPDATED_AT ==========
-- 
-- Mise à jour automatique de la colonne updated_at
-- Appelée avant chaque UPDATE

CREATE OR REPLACE FUNCTION update_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION update_users_updated_at();

-- ========== OPTIONNEL: SOFT DELETE ==========
-- 
-- Si soft delete désiré, décommenter colonne archived_at plus haut
-- Et utiliser ce trigger pour archivage automatique

-- CREATE OR REPLACE FUNCTION soft_delete_users()
-- RETURNS TRIGGER AS $$
-- BEGIN
--     IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL THEN
--         NEW.updated_at = CURRENT_TIMESTAMP;
--     END IF;
--     RETURN NEW;
-- END;
-- $$ LANGUAGE plpgsql;
-- 
-- CREATE TRIGGER trigger_users_soft_delete
-- BEFORE UPDATE ON users
-- FOR EACH ROW
-- EXECUTE FUNCTION soft_delete_users();

-- ========== VUES UTILES ==========

-- Vue: utilisateurs actifs (non archivés)
CREATE OR REPLACE VIEW view_users_active AS
SELECT id, email, name, created_at, updated_at
FROM users
WHERE archived_at IS NULL;

-- Vue: statistiques quotidiennes
CREATE OR REPLACE VIEW view_users_stats AS
SELECT
    DATE(created_at) as creation_date,
    COUNT(*) as total_users,
    MIN(created_at) as first_user,
    MAX(created_at) as last_user
FROM users
WHERE archived_at IS NULL
GROUP BY DATE(created_at)
ORDER BY creation_date DESC;

-- ========== DONNÉES DE TEST (OPTIONNEL) ==========
-- À utiliser uniquement en développement/testing

-- Développement
INSERT INTO users (email, name) VALUES ('alice@example.com', 'Alice') ON CONFLICT DO NOTHING;
INSERT INTO users (email, name) VALUES ('bob@example.com', 'Bob') ON CONFLICT DO NOTHING;
INSERT INTO users (email, name) VALUES ('charlie@example.com', 'Charlie') ON CONFLICT DO NOTHING;

-- ========== VERIFICATION ==========

-- Compter utilisateurs
SELECT COUNT(*) as total_users FROM users;

-- Liste des utilisateurs
SELECT id, email, name, created_at FROM users ORDER BY created_at DESC;

-- ========== MIGRATION DONNÉES (EXEMPLE) ==========
-- 
-- Pattern pour migrer depuis oldShard vers newShard
-- 
-- ÉTAPE 1: Copier (INSERT CONFLICT DO NOTHING pour idempotence)
-- INSERT INTO users_new (email, name, created_at, updated_at)
-- SELECT email, name, created_at, updated_at FROM users_old
-- ON CONFLICT (email) DO NOTHING;
-- 
-- ÉTAPE 2: Vérifier complétude
-- SELECT COUNT(*) FROM users_old WHERE email NOT IN (SELECT email FROM users_new);
-- 
-- ÉTAPE 3: Archiver source (soft delete)
-- UPDATE users_old SET archived_at = CURRENT_TIMESTAMP WHERE email IN (SELECT email FROM users_new);
-- 
-- ÉTAPE 4: Supprimer source (hard delete - après audit)
-- DELETE FROM users_old WHERE archived_at < CURRENT_TIMESTAMP - INTERVAL '7 days';
