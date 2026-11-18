INSERT INTO commands (id,code,name) VALUES (1,'map-demo','DATA DEMO Komando');
UPDATE units SET command_id=1;
INSERT INTO facilities (code,name,unit_id,type_id,latitude,longitude) VALUES
    ('demo-f3','DATA DEMO Fasilitas 2',1,1,-2.001,118.001),
    ('demo-f4','DATA DEMO Fasilitas 3',1,1,-2.002,118.001),
    ('demo-f5','DATA DEMO Fasilitas 4',1,1,-2.003,118.003);
INSERT INTO specialties (id,code,name) VALUES (1,'map-demo','DATA DEMO Spesialisasi');
INSERT INTO personnel (id,nrp,name,unit_id,facility_id,profession_id) VALUES
    ('11111111111111111111111111111111','MAP-DEMO-S','DATA DEMO Spesialis A',1,1,1),
    ('22222222222222222222222222222222','MAP-DEMO-N','DATA DEMO Perawat A',1,1,3);
INSERT INTO personnel_specialties VALUES ('11111111111111111111111111111111',1);
