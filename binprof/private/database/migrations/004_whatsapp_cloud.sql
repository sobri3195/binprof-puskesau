SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE whatsapp_links (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    sender_hash BINARY(32) NOT NULL,
    channel_ref VARCHAR(65) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identity_type ENUM('bsuid','phone') NOT NULL,
    identity_hint VARCHAR(40) NOT NULL,
    recipient_ciphertext TEXT NULL,
    auth_version INT UNSIGNED NOT NULL,
    active_slot TINYINT UNSIGNED NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opted_in_at DATETIME NULL,
    revoked_at DATETIME NULL,
    map_specialty_id INT UNSIGNED NULL,
    context_until DATETIME NULL,
    UNIQUE (user_id,active_slot),
    UNIQUE (sender_hash,active_slot),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (map_specialty_id) REFERENCES specialties(id),
    CHECK (active_slot IS NULL OR active_slot=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_link_challenges (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    code_hash BINARY(32) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    claim_ciphertext TEXT NULL,
    claim_hash BINARY(32) NULL,
    identity_hint VARCHAR(40) NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id,expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_inbox (
    sequence_no BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    provider_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    sender_hash BINARY(32) NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (processed_at,next_attempt_at),
    INDEX (sender_hash,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_outbox (
    sequence_no BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    dedupe_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    kind ENUM('reply','link_help','map_event') NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    provider_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    state ENUM('queued','sending','accepted','sent','delivered','read','failed','unknown','skipped') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    last_provider_at DATETIME NULL,
    error_kind VARCHAR(40) NULL,
    INDEX (state,next_attempt_at),
    INDEX (link_id,created_at),
    FOREIGN KEY (link_id) REFERENCES whatsapp_links(id),
    FOREIGN KEY (event_id) REFERENCES map_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_receipts (
    fingerprint BINARY(32) PRIMARY KEY,
    provider_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    callback_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('sent','delivered','read','failed') NOT NULL,
    occurred_at DATETIME NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    INDEX (applied_at,recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_map_cursor (
    id TINYINT UNSIGNED PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO whatsapp_map_cursor SELECT 1,revision FROM map_revision WHERE id=1;

INSERT INTO permissions (code) VALUES ('whatsapp.link');
INSERT INTO role_permissions (role_code,permission_code)
    SELECT code,'whatsapp.link' FROM roles WHERE code IN ('super_admin','unit_admin','operator','viewer');
INSERT INTO schema_migrations (version) VALUES ('004_whatsapp_cloud');
