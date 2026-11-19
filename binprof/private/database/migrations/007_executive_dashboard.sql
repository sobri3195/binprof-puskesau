-- Apply once after 006. No targets, thresholds, or snapshots are seeded.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE executive_dashboard_preferences (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL, settings_json JSON NOT NULL,
  UNIQUE (user_id,name), FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facility_assessment_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, facility_id INT UNSIGNED NOT NULL,
  version_label VARCHAR(40) NOT NULL, source_reference VARCHAR(250) NOT NULL,
  valid_from DATE NOT NULL, valid_until DATE NULL, freshness_days SMALLINT UNSIGNED NOT NULL,
  critical_gap INT UNSIGNED NOT NULL, certificate_window SMALLINT UNSIGNED NOT NULL,
  certificate_category_id INT UNSIGNED NULL, created_by INT UNSIGNED NOT NULL,
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL,
  UNIQUE (facility_id,version_label), INDEX (facility_id,valid_from,verified_at),
  CHECK (valid_until IS NULL OR valid_until>=valid_from), CHECK (freshness_days BETWEEN 1 AND 730),
  CHECK (certificate_window BETWEEN 1 AND 365), CHECK (critical_gap>0),
  CHECK (verified_by IS NULL OR created_by<>verified_by),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (certificate_category_id) REFERENCES personnel_record_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facility_staffing_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, facility_id INT UNSIGNED NOT NULL, profession_id INT UNSIGNED NOT NULL,
  version_label VARCHAR(40) NOT NULL, target_count INT UNSIGNED NOT NULL, source_reference VARCHAR(250) NOT NULL,
  valid_from DATE NOT NULL, valid_until DATE NULL, created_by INT UNSIGNED NOT NULL,
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL,
  UNIQUE (facility_id,profession_id,version_label), INDEX (facility_id,profession_id,valid_from,verified_at),
  CHECK (valid_until IS NULL OR valid_until>=valid_from), CHECK (verified_by IS NULL OR created_by<>verified_by),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (profession_id) REFERENCES professions(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_roster_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, facility_id INT UNSIGNED NOT NULL, roster_on DATE NOT NULL,
  roster_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source_reference VARCHAR(250) NOT NULL,
  submitted_by INT UNSIGNED NOT NULL, pic_id INT UNSIGNED NOT NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL,
  INDEX (facility_id,roster_on,id), CHECK (verified_by IS NULL OR verified_by<>submitted_by),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (pic_id) REFERENCES users(id), FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_event_verifications (
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  source_reference VARCHAR(250) NOT NULL, verified_by INT UNSIGNED NOT NULL,
  verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES map_events(id), FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_directives (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, facility_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL, instruction TEXT NOT NULL,
  kind ENUM('general','staffing','certificate','retirement','event','quality') NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL, due_on DATE NOT NULL,
  state ENUM('draft','issued','accepted','working','review','done','cancelled') NOT NULL DEFAULT 'draft',
  pic_id INT UNSIGNED NOT NULL, created_by INT UNSIGNED NOT NULL,
  plan_reference VARCHAR(250) NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (facility_id,state,due_on), INDEX (pic_id,state),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (pic_id) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_directive_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, directive_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT UNSIGNED NOT NULL, row_version INT UNSIGNED NOT NULL, action VARCHAR(24) NOT NULL,
  from_state VARCHAR(16) NULL, to_state VARCHAR(16) NOT NULL, note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (directive_id,row_version), FOREIGN KEY (directive_id) REFERENCES executive_directives(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_directive_reads (
  directive_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (directive_id,user_id), FOREIGN KEY (directive_id) REFERENCES executive_directives(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE executive_directive_files (
  directive_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  uploaded_by INT UNSIGNED NOT NULL, PRIMARY KEY (directive_id,file_id),
  FOREIGN KEY (directive_id) REFERENCES executive_directives(id), FOREIGN KEY (file_id) REFERENCES file_objects(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facility_metric_snapshots (
  facility_id INT UNSIGNED NOT NULL, snapshot_on DATE NOT NULL, snapshot_json JSON NOT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, captured_by INT UNSIGNED NOT NULL,
  PRIMARY KEY (facility_id,snapshot_on), INDEX (snapshot_on,facility_id),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (captured_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles VALUES ('executive_leader','Pimpinan');
INSERT INTO permissions VALUES ('executive.home'),('executive.view'),('executive.export'),('executive.issue'),
  ('executive.review'),('executive.work'),('executive.configure'),('executive.verify'),('executive.snapshot');
INSERT INTO role_permissions (role_code,permission_code)
  SELECT 'super_admin',code FROM permissions WHERE code LIKE 'executive.%' AND code<>'executive.home';
INSERT INTO role_permissions VALUES
  ('executive_leader','executive.home'),('executive_leader','executive.view'),('executive_leader','executive.export'),
  ('executive_leader','executive.issue'),('executive_leader','executive.review'),('executive_leader','executive.verify'),
  ('executive_leader','executive.work'),('executive_leader','executive.snapshot'),
  ('executive_leader','dashboard'),('executive_leader','map'),('executive_leader','notifications'),
  ('executive_leader','personnel.read'),('executive_leader','training.read'),('executive_leader','retirement.read'),
  ('executive_leader','retirement.summary'),
  ('unit_admin','executive.work'),('unit_admin','executive.configure'),('unit_admin','executive.verify'),
  ('operator','executive.work');
INSERT INTO schema_migrations VALUES ('007_executive_dashboard',CURRENT_TIMESTAMP);
