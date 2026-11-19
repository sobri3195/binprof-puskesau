-- Apply once after 007. Existing accounts, scopes, and access assignments are preserved.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

START TRANSACTION;

INSERT INTO roles (code,name) VALUES
  ('kapuskesau','Kapuskesau'),
  ('dirbinprof','Dirbinprof'),
  ('kasipa','Kasipa'),
  ('kasibata','Kasibata'),
  ('staf_binprof','Staf Binprof');

INSERT INTO permissions (code) VALUES ('personnel.identity');

-- Existing profile readers retain their contact/NIK access after introducing the separate permission.
INSERT INTO role_permissions (role_code,permission_code)
  SELECT role_code,'personnel.identity' FROM role_permissions WHERE permission_code='personnel.read';

-- Shared scoped read access and assigned executive work.
INSERT INTO role_permissions (role_code,permission_code)
  SELECT r.code,p.code FROM roles r CROSS JOIN permissions p
  WHERE r.code IN ('kapuskesau','dirbinprof','kasipa','kasibata','staf_binprof')
    AND p.code IN ('dashboard','map','notifications','executive.work','personnel.read','training.read',
      'retirement.summary','retirement.read','personnel.decrees.read');

-- Leadership, aggregate reports, and the independently authorized retirement stages.
INSERT INTO role_permissions (role_code,permission_code)
  SELECT r.code,p.code FROM roles r CROSS JOIN permissions p
  WHERE r.code IN ('kapuskesau','dirbinprof')
    AND p.code IN ('executive.home','executive.view','executive.issue','executive.review','executive.export',
      'executive.snapshot','map.export','personnel.decrees.download');
INSERT INTO role_permissions (role_code,permission_code) VALUES
  ('kapuskesau','retirement.approve'),
  ('dirbinprof','retirement.verify'),
  ('dirbinprof','retirement.apply'),
  ('dirbinprof','executive.verify');

-- Operational entries, documents, and draft submission remain scoped.
INSERT INTO role_permissions (role_code,permission_code)
  SELECT r.code,p.code FROM roles r CROSS JOIN permissions p
  WHERE r.code IN ('kasipa','kasibata','staf_binprof')
    AND p.code IN ('personnel.write','training.write','training.download','transfers.request','retirement.write','map.respond',
      'personnel.certificates.read','personnel.certificates.write','personnel.certificates.download',
      'personnel.decrees.write','personnel.decrees.download',
      'personnel.diplomas.read','personnel.diplomas.write','personnel.diplomas.download',
      'personnel.military.read','personnel.military.write','personnel.military.download',
      'personnel.general.read','personnel.general.write','personnel.general.download');

-- Section heads have the same type of authority in their individually assigned scopes.
INSERT INTO role_permissions (role_code,permission_code)
  SELECT r.code,p.code FROM roles r CROSS JOIN permissions p
  WHERE r.code IN ('kasipa','kasibata')
    AND p.code IN ('executive.configure','map.manage','personnel.certificates.verify','personnel.decrees.verify',
      'personnel.diplomas.verify','personnel.military.verify','personnel.general.verify');

INSERT INTO schema_migrations (version) VALUES ('008_binprof_position_roles');

COMMIT;
