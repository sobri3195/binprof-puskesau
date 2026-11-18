-- Apply once after migrations 001 and 002. Clinical privileges start empty.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE user_clinical_privileges (
  user_id INT UNSIGNED NOT NULL, facility_id INT UNSIGNED NOT NULL,
  can_read BOOLEAN NOT NULL, can_write BOOLEAN NOT NULL, can_export BOOLEAN NOT NULL, can_approve BOOLEAN NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE, designated_by INT UNSIGNED NULL,
  PRIMARY KEY (user_id,facility_id), FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (facility_id) REFERENCES facilities(id), FOREIGN KEY (designated_by) REFERENCES users(id),
  CHECK (designated_by IS NULL OR user_id <> designated_by),
  CHECK (can_read OR (NOT can_write AND NOT can_export))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_patients (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  personnel_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE patient_care_assignments (
  patient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id INT UNSIGNED NOT NULL, facility_id INT UNSIGNED NOT NULL, assigned_by INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  PRIMARY KEY (patient_id,user_id,facility_id), CHECK (assigned_by <> user_id),
  FOREIGN KEY (patient_id) REFERENCES clinical_patients(id), FOREIGN KEY (assigned_by) REFERENCES users(id),
  FOREIGN KEY (user_id,facility_id) REFERENCES user_clinical_privileges(user_id,facility_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_access_requests (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  patient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  requester_id INT UNSIGNED NOT NULL, workplace_id INT UNSIGNED NOT NULL, source_facility_id INT UNSIGNED NOT NULL,
  purpose ENUM('rikkes','dikbangum','referral','follow_up') NOT NULL,
  access_scope ENUM('read','export') NOT NULL, justification_ciphertext TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', approver_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL,
  CHECK (workplace_id <> source_facility_id), CHECK (approver_id IS NULL OR approver_id <> requester_id),
  FOREIGN KEY (patient_id) REFERENCES clinical_patients(id),
  FOREIGN KEY (requester_id,workplace_id) REFERENCES user_clinical_privileges(user_id,facility_id),
  FOREIGN KEY (source_facility_id) REFERENCES facilities(id), FOREIGN KEY (approver_id) REFERENCES users(id),
  INDEX (source_facility_id,status), INDEX (requester_id,created_at),
  INDEX clinical_access_binding (patient_id,requester_id,workplace_id,source_facility_id,purpose,access_scope,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_access_grants (
  request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  FOREIGN KEY (request_id) REFERENCES clinical_access_requests(id), INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_encounters (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  patient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  facility_id INT UNSIGNED NOT NULL, encounter_on DATE NOT NULL,
  kind ENUM('visit','rikkes') NOT NULL, row_version INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (patient_id) REFERENCES clinical_patients(id), FOREIGN KEY (facility_id) REFERENCES facilities(id),
  INDEX (patient_id,facility_id,encounter_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_record_versions (
  encounter_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, version INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL, state ENUM('draft','signed','amended') NOT NULL,
  payload_ciphertext MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (encounter_id,version),
  FOREIGN KEY (encounter_id) REFERENCES clinical_encounters(id), FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id INT UNSIGNED NOT NULL, facility_id INT UNSIGNED NULL,
  patient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, action VARCHAR(40) NOT NULL,
  purpose ENUM('rikkes','dikbangum','referral','follow_up') NULL,
  result ENUM('success','denied') NOT NULL, reason_ciphertext TEXT NULL, request_id CHAR(32) CHARACTER SET ascii NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id), FOREIGN KEY (facility_id) REFERENCES facilities(id),
  FOREIGN KEY (patient_id) REFERENCES clinical_patients(id),
  INDEX (actor_id,action,created_at), INDEX (facility_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE clinical_documents (
  encounter_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version INT UNSIGNED NOT NULL,
  file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (encounter_id,version,file_id),
  FOREIGN KEY (encounter_id,version) REFERENCES clinical_record_versions(encounter_id,version),
  FOREIGN KEY (file_id) REFERENCES file_objects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (version) VALUES ('003_clinical_records');
