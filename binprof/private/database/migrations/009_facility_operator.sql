-- Apply once after 008. RSAU and FKTP share one role and the existing facility scopes.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

START TRANSACTION;

INSERT INTO facility_types (code,name)
  SELECT 'fktp','FKTP' WHERE NOT EXISTS (SELECT 1 FROM facility_types WHERE code='fktp');

INSERT INTO roles (code,name) VALUES ('operator_fasilitas','Operator Fasilitas RSAU/FKTP');
INSERT INTO permissions (code) VALUES ('map.report');

INSERT INTO role_permissions (role_code,permission_code)
  SELECT 'operator_fasilitas',code FROM permissions
  WHERE code IN ('dashboard','map','map.report','notifications','executive.work',
    'personnel.read','personnel.write','training.read','training.write','training.download',
    'retirement.summary','retirement.read','retirement.write',
    'personnel.certificates.read','personnel.certificates.write','personnel.certificates.download',
    'personnel.decrees.read','personnel.decrees.write','personnel.decrees.download',
    'personnel.diplomas.read','personnel.diplomas.write','personnel.diplomas.download',
    'personnel.military.read','personnel.military.write','personnel.military.download',
    'personnel.general.read','personnel.general.write','personnel.general.download');

INSERT INTO schema_migrations (version) VALUES ('009_facility_operator');

COMMIT;
