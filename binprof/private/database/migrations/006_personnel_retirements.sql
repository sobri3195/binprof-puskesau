-- Apply once after 005. All existing personnel remain as they are.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE personnel ADD COLUMN retired_on DATE NULL, ADD INDEX personnel_retired_on (retired_on);
ALTER TABLE notifications ADD COLUMN cancelled_at DATETIME NULL;

CREATE TABLE retirement_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL, version_label VARCHAR(32) NOT NULL, category VARCHAR(100) NOT NULL,
  source_reference VARCHAR(250) NOT NULL, age_months SMALLINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL, valid_until DATE NULL,
  created_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL,
  UNIQUE (code,version_label), CHECK (age_months BETWEEN 1 AND 1800),
  CHECK (valid_until IS NULL OR valid_until>=valid_from),
  CHECK (verified_by IS NULL OR verified_by<>created_by),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_retirements (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_slot TINYINT UNSIGNED NULL DEFAULT 1,
  state ENUM('draft','submitted','verified','approved','effective','returned','cancelled') NOT NULL DEFAULT 'draft',
  category VARCHAR(100) NOT NULL,
  rule_id INT UNSIGNED NULL, projection_method ENUM('unknown','manual','rule') NOT NULL DEFAULT 'unknown',
  planned_on DATE NULL, projection_source VARCHAR(250) NULL, projected_at DATETIME NULL,
  birth_on DATE NULL, birth_source VARCHAR(250) NULL, birth_entered_by INT UNSIGNED NULL,
  birth_verified_by INT UNSIGNED NULL, birth_verified_at DATETIME NULL,
  decree_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, decree_version INT UNSIGNED NULL,
  effective_on DATE NULL, effective_at DATETIME NULL, handover_on DATE NULL,
  last_apply_checked_at DATETIME NULL, last_apply_error VARCHAR(250) NULL,
  last_rank_id INT UNSIGNED NULL, last_unit_id INT UNSIGNED NULL,
  last_facility_id INT UNSIGNED NULL, last_position VARCHAR(160) NULL,
  last_rank_name VARCHAR(160) NULL, last_unit_name VARCHAR(160) NULL, last_facility_name VARCHAR(160) NULL,
  pic_id INT UNSIGNED NOT NULL, created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NULL, verified_by INT UNSIGNED NULL, approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL, approved_at DATETIME NULL, notes TEXT NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1, reminder_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (personnel_id,active_slot), INDEX (state,effective_on), INDEX (planned_on,state),
  CHECK (active_slot IS NULL OR active_slot=1),
  CHECK ((state='cancelled') = (active_slot IS NULL)),
  CHECK ((state='effective') = (effective_at IS NOT NULL)),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (rule_id) REFERENCES retirement_rules(id),
  FOREIGN KEY (decree_id,personnel_id) REFERENCES personnel_decree_extracts(id,personnel_id),
  FOREIGN KEY (last_rank_id) REFERENCES ranks(id), FOREIGN KEY (last_unit_id) REFERENCES units(id),
  FOREIGN KEY (last_facility_id,last_unit_id) REFERENCES facilities(id,unit_id),
  FOREIGN KEY (pic_id) REFERENCES users(id), FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (birth_entered_by) REFERENCES users(id), FOREIGN KEY (birth_verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_retirement_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retirement_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL, state VARCHAR(20) NOT NULL, reason VARCHAR(250) NULL,
  planned_on DATE NULL, effective_on DATE NULL, decree_version INT UNSIGNED NULL,
  request_id CHAR(32) CHARACTER SET ascii NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (retirement_id,row_version), FOREIGN KEY (retirement_id) REFERENCES personnel_retirements(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retirement_checklist_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE, label VARCHAR(160) NOT NULL,
  item_type ENUM('document','handover') NOT NULL,
  required BOOLEAN NOT NULL DEFAULT TRUE, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_retirement_checklists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retirement_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  template_id INT UNSIGNED NOT NULL, label VARCHAR(160) NOT NULL,
  item_type ENUM('document','handover') NOT NULL, required BOOLEAN NOT NULL,
  completed_on DATE NULL, completed_by INT UNSIGNED NULL, note VARCHAR(250) NULL,
  UNIQUE (retirement_id,template_id), FOREIGN KEY (retirement_id) REFERENCES personnel_retirements(id),
  FOREIGN KEY (template_id) REFERENCES retirement_checklist_templates(id), FOREIGN KEY (completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retirement_document_links (
  retirement_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decree_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  linked_by INT UNSIGNED NOT NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (retirement_id,decree_id), FOREIGN KEY (retirement_id) REFERENCES personnel_retirements(id),
  FOREIGN KEY (decree_id) REFERENCES personnel_decree_extracts(id), FOREIGN KEY (linked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_account_links (
  user_id INT UNSIGNED PRIMARY KEY, personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  verified_by INT UNSIGNED NOT NULL, verification_reference VARCHAR(250) NOT NULL,
  verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retirement_assignments (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  retirement_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id INT UNSIGNED NOT NULL, unit_id INT UNSIGNED NULL, facility_id INT UNSIGNED NULL,
  authorization_reference VARCHAR(250) NOT NULL, expires_on DATE NOT NULL,
  granted_by INT UNSIGNED NOT NULL, granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active_slot TINYINT UNSIGNED NULL DEFAULT 1, revoked_at DATETIME NULL,
  UNIQUE (user_id,active_slot),
  CHECK ((unit_id IS NULL) <> (facility_id IS NULL)),
  CHECK (active_slot IS NULL OR active_slot=1),
  FOREIGN KEY (retirement_id) REFERENCES personnel_retirements(id), FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (unit_id) REFERENCES units(id), FOREIGN KEY (facility_id) REFERENCES facilities(id),
  FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retirement_reminder_preferences (
  user_id INT UNSIGNED PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT FALSE,
  months TINYINT UNSIGNED NOT NULL DEFAULT 6,
  CHECK (months IN (1,3,6,12,24)), FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code) VALUES ('retirement.summary'),('retirement.read'),('retirement.write'),
  ('retirement.verify'),('retirement.approve'),('retirement.apply'),('retirement.manage'),('retirement.account');
INSERT INTO role_permissions (role_code,permission_code)
  SELECT 'super_admin',code FROM permissions WHERE code LIKE 'retirement.%';
INSERT INTO role_permissions (role_code,permission_code) VALUES
  ('unit_admin','retirement.summary'),('unit_admin','retirement.read'),('unit_admin','retirement.write'),('unit_admin','retirement.verify'),
  ('operator','retirement.summary'),('operator','retirement.read'),('operator','retirement.write'),
  ('viewer','retirement.summary');

INSERT INTO schema_migrations (version) VALUES ('006_personnel_retirements');
