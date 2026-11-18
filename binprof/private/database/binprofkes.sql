-- Import once into an empty MySQL/MariaDB database selected by the operator.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE schema_migrations (
  version VARCHAR(40) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE application_state (
  id TINYINT UNSIGNED PRIMARY KEY, installed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE settings (
  name VARCHAR(80) PRIMARY KEY, value VARCHAR(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  code VARCHAR(32) PRIMARY KEY, name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE permissions (
  code VARCHAR(48) PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE role_permissions (
  role_code VARCHAR(32) NOT NULL, permission_code VARCHAR(48) NOT NULL,
  PRIMARY KEY (role_code, permission_code),
  FOREIGN KEY (role_code) REFERENCES roles(code), FOREIGN KEY (permission_code) REFERENCES permissions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE commands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, command_id INT UNSIGNED NULL,
  FOREIGN KEY (command_id) REFERENCES commands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE ranks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, group_name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE corps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE professions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE specialties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE facility_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE facilities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
  unit_id INT UNSIGNED NOT NULL, type_id INT UNSIGNED NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
  latitude DECIMAL(9,6) NULL, longitude DECIMAL(9,6) NULL, address VARCHAR(250) NULL,
  UNIQUE (id, unit_id),
  CHECK ((latitude IS NULL) = (longitude IS NULL)), CHECK (latitude BETWEEN -90 AND 90),
  CHECK (longitude BETWEEN -180 AND 180),
  FOREIGN KEY (unit_id) REFERENCES units(id), FOREIGN KEY (type_id) REFERENCES facility_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL, role_code VARCHAR(32) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
  session_version INT UNSIGNED NOT NULL DEFAULT 1, last_login_at DATETIME NULL,
  FOREIGN KEY (role_code) REFERENCES roles(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_unit_scopes (
  user_id INT UNSIGNED NOT NULL, unit_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, unit_id),
  FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_facility_scopes (
  user_id INT UNSIGNED NOT NULL, facility_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, facility_id),
  FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (facility_id) REFERENCES facilities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE auth_sessions (
  handle_hash BINARY(32) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, session_version INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id, revoked_at), INDEX (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_hash BINARY(32) NOT NULL,
  ip_hash BINARY(32) NOT NULL, attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (account_hash, attempted_at), INDEX (ip_hash, attempted_at), INDEX (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  nrp VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, unit_id INT UNSIGNED NOT NULL,
  facility_id INT UNSIGNED NULL, rank_id INT UNSIGNED NULL, corps_id INT UNSIGNED NULL, profession_id INT UNSIGNED NOT NULL,
  position_title VARCHAR(160) NOT NULL DEFAULT '', occupation VARCHAR(160) NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE, bp_status ENUM('unknown','yes','no') NOT NULL DEFAULT 'unknown',
  promotion_date DATE NULL, archived_at DATETIME NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  contact_ciphertext TEXT NULL, nik_ciphertext TEXT NULL, nik_lookup_hash BINARY(32) NULL UNIQUE,
  INDEX (unit_id, archived_at, active), INDEX (name), INDEX (promotion_date), INDEX (profession_id, active),
  FOREIGN KEY (unit_id) REFERENCES units(id), FOREIGN KEY (facility_id, unit_id) REFERENCES facilities(id, unit_id),
  FOREIGN KEY (rank_id) REFERENCES ranks(id), FOREIGN KEY (corps_id) REFERENCES corps(id),
  FOREIGN KEY (profession_id) REFERENCES professions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE personnel_specialties (
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, specialty_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (personnel_id, specialty_id), FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  FOREIGN KEY (specialty_id) REFERENCES specialties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE service_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unit_id INT UNSIGNED NOT NULL, position_title VARCHAR(160) NOT NULL,
  started_on DATE NOT NULL, ended_on DATE NULL,
  CHECK (ended_on IS NULL OR ended_on >= started_on), INDEX (personnel_id, started_on),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE awards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(160) NOT NULL, issuer VARCHAR(160) NOT NULL, awarded_on DATE NOT NULL, notes TEXT NOT NULL,
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE works (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(160) NOT NULL, kind VARCHAR(100) NOT NULL, work_year SMALLINT UNSIGNED NOT NULL, description TEXT NOT NULL,
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE unit_transfers (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  from_unit_id INT UNSIGNED NOT NULL, to_unit_id INT UNSIGNED NOT NULL, to_facility_id INT UNSIGNED NULL,
  requester_id INT UNSIGNED NOT NULL, approver_id INT UNSIGNED NULL, effective_on DATE NOT NULL,
  status ENUM('pending','approved','applied','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL, applied_at DATETIME NULL,
  CHECK (from_unit_id <> to_unit_id), CHECK (approver_id IS NULL OR requester_id <> approver_id),
  INDEX (status, effective_on), INDEX (personnel_id, status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  FOREIGN KEY (from_unit_id) REFERENCES units(id), FOREIGN KEY (to_unit_id) REFERENCES units(id),
  FOREIGN KEY (to_facility_id, to_unit_id) REFERENCES facilities(id, unit_id),
  FOREIGN KEY (requester_id) REFERENCES users(id), FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_records (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type_id INT UNSIGNED NOT NULL, organizer VARCHAR(160) NOT NULL, started_on DATE NULL, ended_on DATE NULL,
  expires_on DATE NULL, completed BOOLEAN NOT NULL DEFAULT FALSE,
  notes TEXT NOT NULL, archived_at DATETIME NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  CHECK (started_on IS NULL OR ended_on IS NULL OR ended_on >= started_on),
  CHECK (expires_on IS NULL OR ended_on IS NULL OR expires_on >= ended_on),
  INDEX (expires_on, archived_at), INDEX (started_on), FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  FOREIGN KEY (type_id) REFERENCES training_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE file_objects (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, original_name VARCHAR(180) NOT NULL,
  media_type VARCHAR(80) NOT NULL, byte_length INT UNSIGNED NOT NULL, key_id VARCHAR(32) NOT NULL,
  ciphertext_sha256 BINARY(32) NOT NULL, scan_status ENUM('unavailable','clean') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE training_documents (
  training_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, PRIMARY KEY (training_id, file_id),
  FOREIGN KEY (training_id) REFERENCES training_records(id), FOREIGN KEY (file_id) REFERENCES file_objects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, event_key VARCHAR(150) NOT NULL,
  message VARCHAR(250) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
  UNIQUE (user_id, event_key), INDEX (user_id, read_at, id), FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id INT UNSIGNED NULL, unit_id INT UNSIGNED NULL,
  action VARCHAR(48) NOT NULL, entity VARCHAR(48) NOT NULL, entity_key VARCHAR(48) NULL,
  result ENUM('success','denied') NOT NULL, request_id CHAR(32) CHARACTER SET ascii NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (unit_id, created_at), INDEX (actor_id, created_at), INDEX (created_at),
  FOREIGN KEY (actor_id) REFERENCES users(id), FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('001_initial');
INSERT INTO application_state (id) VALUES (1);
INSERT INTO roles VALUES ('super_admin','Super Admin'),('unit_admin','Admin Satuan'),('operator','Operator'),('viewer','Viewer');
INSERT INTO permissions VALUES ('dashboard'),('map'),('personnel.read'),('personnel.write'),('personnel.export'),
 ('training.read'),('training.write'),('training.export'),('training.download'),('transfers.request'),('transfers.approve'),
 ('notifications'),('audit'),('users'),('masters');
INSERT INTO role_permissions SELECT 'super_admin', code FROM permissions;
INSERT INTO role_permissions SELECT 'unit_admin', code FROM permissions WHERE code NOT IN ('users','masters','transfers.approve');
INSERT INTO role_permissions SELECT 'operator', code FROM permissions WHERE code IN
 ('dashboard','map','personnel.read','personnel.write','training.read','training.write','training.download','transfers.request','notifications');
INSERT INTO role_permissions SELECT 'viewer', code FROM permissions WHERE code IN ('dashboard','map','notifications');
INSERT INTO professions (code,name) VALUES ('doctor','Dokter'),('dentist','Dokter Gigi'),('nurse','Perawat'),('other','Lainnya');
INSERT INTO facility_types (code,name) VALUES ('lanud','Lanud'),('rsau','RSAU'),('kodau','Kodau'),
 ('koopsau','Koopsau'),('satrad','Satrad'),('puskesau','Puskesau');
INSERT INTO settings VALUES ('promotion_window_days','90'),('certificate_window_days','90');
