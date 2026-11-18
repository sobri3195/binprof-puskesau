-- Apply once after binprofkes.sql. Take a backup before schema changes.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE facilities ADD COLUMN updated_at DATETIME NULL;
CREATE INDEX personnel_facility_map ON personnel (facility_id,archived_at,active,profession_id);
CREATE TABLE map_revision (
    id TINYINT UNSIGNED PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL,
    refresh_revision BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO map_revision VALUES (1, 0, 0);
CREATE TABLE map_events (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    facility_id INT UNSIGNED NOT NULL,
    category ENUM('operations','data','training') NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL,
    active_slot TINYINT UNSIGNED NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_by INT UNSIGNED NULL,
    acknowledged_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    CHECK (active_slot IS NULL OR active_slot=1),
    UNIQUE (facility_id, category, active_slot),
    INDEX (revision), INDEX (facility_id, active_slot, created_at),
    FOREIGN KEY (facility_id) REFERENCES facilities(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (acknowledged_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE map_event_reads (
    user_id INT UNSIGNED NOT NULL,
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,event_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (event_id) REFERENCES map_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE map_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    operations_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    data_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    training_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    muted BOOLEAN NOT NULL DEFAULT FALSE,
    quiet_start TIME NULL, quiet_end TIME NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    CHECK ((quiet_start IS NULL) = (quiet_end IS NULL)),
    CHECK (quiet_start IS NULL OR quiet_start <> quiet_end),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE map_saved_views (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    filters_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id,name),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions VALUES ('map.export'),('map.respond'),('map.manage');
INSERT INTO role_permissions VALUES
    ('super_admin','map.export'),('super_admin','map.respond'),('super_admin','map.manage'),
    ('unit_admin','map.export'),('unit_admin','map.respond'),('unit_admin','map.manage'),('operator','map.respond');
INSERT INTO schema_migrations (version) VALUES ('002_interactive_maps');
