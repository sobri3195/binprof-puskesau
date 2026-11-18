-- Apply after migration 004. This migration is additive.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE personnel_record_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('cases','certificates','decrees','military','general') NOT NULL,
  code VARCHAR(32) NOT NULL, name VARCHAR(160) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (kind,code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_case_permissions (
  user_id INT UNSIGNED NOT NULL,
  permission_code VARCHAR(48) NOT NULL,
  granted_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,permission_code),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (granted_by) REFERENCES users(id),
  FOREIGN KEY (permission_code) REFERENCES permissions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE training_records ADD UNIQUE KEY training_personnel_binding (id,personnel_id);

CREATE TABLE personnel_cases (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  registration_no VARCHAR(100) NOT NULL, title VARCHAR(160) NOT NULL,
  reported_on DATE NOT NULL, occurred_on DATE NULL, handling_unit_id INT UNSIGNED NOT NULL,
  handler_id INT UNSIGNED NOT NULL, report_source VARCHAR(160) NOT NULL, summary TEXT NOT NULL,
  case_status ENUM('reported','review','handling','closed') NOT NULL DEFAULT 'reported',
  follow_up_on DATE NULL, decision TEXT NULL, decision_no VARCHAR(100) NULL, decision_on DATE NULL, closed_on DATE NULL,
  notes TEXT NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, verification_notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (id,personnel_id), INDEX (personnel_id,archived_at,category_id,verification_status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (category_id) REFERENCES personnel_record_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (archived_by) REFERENCES users(id),
  UNIQUE (handling_unit_id,registration_no), INDEX (handling_unit_id,case_status,follow_up_on),
  FOREIGN KEY (handling_unit_id) REFERENCES units(id), FOREIGN KEY (handler_id) REFERENCES users(id),
  CHECK (occurred_on IS NULL OR occurred_on<=reported_on),
  CHECK ((case_status='closed') = (closed_on IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_certificates (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL, document_no VARCHAR(100) NULL, issuer VARCHAR(160) NULL, activity_name VARCHAR(160) NULL,
  issued_on DATE NULL, valid_from DATE NULL, expires_on DATE NULL,
  validity_kind ENUM('dated','unlimited','unknown') NOT NULL DEFAULT 'unknown', reminder_days SMALLINT UNSIGNED NULL,
  training_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, verified_training_version INT UNSIGNED NULL,
  notes TEXT NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, verification_notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (id,personnel_id), INDEX (personnel_id,archived_at,category_id,verification_status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (category_id) REFERENCES personnel_record_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (archived_by) REFERENCES users(id),
  UNIQUE (training_id), INDEX (expires_on,archived_at),
  FOREIGN KEY (training_id,personnel_id) REFERENCES training_records(id,personnel_id),
  CHECK (training_id IS NULL OR expires_on IS NULL),
  CHECK (training_id IS NOT NULL OR ((validity_kind='dated') = (expires_on IS NOT NULL))),
  CHECK (reminder_days IS NULL OR reminder_days BETWEEN 1 AND 365)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_decree_extracts (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL, document_no VARCHAR(100) NOT NULL, issuer VARCHAR(160) NOT NULL,
  decreed_on DATE NOT NULL, effective_on DATE NULL, parent_reference VARCHAR(160) NULL, summary TEXT NOT NULL,
  service_history_id BIGINT UNSIGNED NULL, transfer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  document_status ENUM('current','replaced','revoked') NOT NULL DEFAULT 'current',
  replacement_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  notes TEXT NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, verification_notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (id,personnel_id), INDEX (personnel_id,archived_at,category_id,verification_status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (category_id) REFERENCES personnel_record_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (archived_by) REFERENCES users(id),
  INDEX (document_no,decreed_on), UNIQUE (replacement_id),
  FOREIGN KEY (service_history_id) REFERENCES service_history(id),
  FOREIGN KEY (transfer_id) REFERENCES unit_transfers(id),
  FOREIGN KEY (replacement_id,personnel_id) REFERENCES personnel_decree_extracts(id,personnel_id),
  CHECK ((document_status='replaced') = (replacement_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_diplomas (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  document_no VARCHAR(100) NOT NULL, institution VARCHAR(160) NOT NULL, study_program VARCHAR(160) NOT NULL,
  degree_title VARCHAR(100) NULL, graduation_year SMALLINT UNSIGNED NOT NULL, graduated_on DATE NULL, issued_on DATE NULL,
  legalization_note VARCHAR(250) NULL,
  notes TEXT NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, verification_notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (id,personnel_id), INDEX (personnel_id,archived_at,category_id,verification_status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (category_id) REFERENCES personnel_record_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (archived_by) REFERENCES users(id),
  INDEX (institution,graduation_year),
  CHECK (graduated_on IS NULL OR YEAR(graduated_on)=graduation_year),
  CHECK (issued_on IS NULL OR graduated_on IS NULL OR issued_on>=graduated_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_education (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  education_type ENUM('military','general') NOT NULL,
  title VARCHAR(160) NULL, institution VARCHAR(160) NOT NULL, cohort VARCHAR(100) NULL, faculty VARCHAR(160) NULL,
  study_program VARCHAR(160) NULL, degree_title VARCHAR(100) NULL, qualification VARCHAR(160) NULL,
  start_year SMALLINT UNSIGNED NOT NULL, end_year SMALLINT UNSIGNED NULL,
  started_on DATE NULL, ended_on DATE NULL, graduated_on DATE NULL,
  education_status ENUM('planned','ongoing','graduated','incomplete') NOT NULL DEFAULT 'planned',
  diploma_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  certificate_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  notes TEXT NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, verification_notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL, updated_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL, archived_by INT UNSIGNED NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (id,personnel_id), INDEX (personnel_id,archived_at,category_id,verification_status),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (category_id) REFERENCES personnel_record_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id), FOREIGN KEY (archived_by) REFERENCES users(id),
  UNIQUE (diploma_id), INDEX (personnel_id,education_type,education_status,end_year),
  FOREIGN KEY (diploma_id,personnel_id) REFERENCES personnel_diplomas(id,personnel_id),
  FOREIGN KEY (certificate_id,personnel_id) REFERENCES personnel_certificates(id,personnel_id),
  CHECK (education_type='military' OR certificate_id IS NULL),
  CHECK (end_year IS NULL OR end_year>=start_year),
  CHECK (started_on IS NULL OR ended_on IS NULL OR ended_on>=started_on),
  CHECK (graduated_on IS NULL OR education_status='graduated'),
  CHECK (education_status<>'graduated' OR end_year IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_case_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT UNSIGNED NOT NULL, occurred_on DATE NOT NULL,
  prior_status ENUM('reported','review','handling','closed') NOT NULL,
  next_status ENUM('reported','review','handling','closed') NOT NULL,
  note TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (case_id,occurred_on,id), FOREIGN KEY (case_id) REFERENCES personnel_cases(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_record_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  record_kind ENUM('cases','certificates','decrees','diplomas','military','general') NOT NULL,
  record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT UNSIGNED NOT NULL, row_version INT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL, request_id CHAR(32) CHARACTER SET ascii NOT NULL,
  change_summary VARCHAR(250) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (personnel_id,record_kind,record_id,id),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personnel_record_attachments (
  file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, certificate_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  decree_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, diploma_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, education_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  purpose ENUM('document','transcript','legalization','support') NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK ((case_id IS NOT NULL)+(certificate_id IS NOT NULL)+(decree_id IS NOT NULL)+
    (diploma_id IS NOT NULL)+(education_id IS NOT NULL)=1),
  FOREIGN KEY (file_id) REFERENCES file_objects(id), FOREIGN KEY (case_id) REFERENCES personnel_cases(id),
  FOREIGN KEY (certificate_id) REFERENCES personnel_certificates(id), FOREIGN KEY (decree_id) REFERENCES personnel_decree_extracts(id),
  FOREIGN KEY (diploma_id) REFERENCES personnel_diplomas(id), FOREIGN KEY (education_id) REFERENCES personnel_education(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code) VALUES
  ('personnel.cases.read'),('personnel.cases.write'),('personnel.cases.verify'),('personnel.cases.download'),('personnel.cases.export'),
  ('personnel.certificates.read'),('personnel.certificates.write'),('personnel.certificates.verify'),('personnel.certificates.download'),('personnel.certificates.export'),
  ('personnel.decrees.read'),('personnel.decrees.write'),('personnel.decrees.verify'),('personnel.decrees.download'),('personnel.decrees.export'),
  ('personnel.diplomas.read'),('personnel.diplomas.write'),('personnel.diplomas.verify'),('personnel.diplomas.download'),('personnel.diplomas.export'),
  ('personnel.military.read'),('personnel.military.write'),('personnel.military.verify'),('personnel.military.download'),('personnel.military.export'),
  ('personnel.general.read'),('personnel.general.write'),('personnel.general.verify'),('personnel.general.download'),('personnel.general.export');
INSERT INTO role_permissions (role_code,permission_code)
  SELECT r.code,p.code FROM roles r CROSS JOIN permissions p
  WHERE r.code IN ('super_admin','unit_admin','operator')
    AND SUBSTRING_INDEX(p.code,'.',2) IN ('personnel.certificates','personnel.decrees',
      'personnel.diplomas','personnel.military','personnel.general')
    AND (r.code<>'operator' OR SUBSTRING_INDEX(p.code,'.',-1) IN ('read','write','download'));
-- Case permissions are issued explicitly per user. No role receives them here.
INSERT INTO schema_migrations (version) VALUES ('005_personnel_records');
