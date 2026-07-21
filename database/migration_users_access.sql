USE rentsys;

INSERT IGNORE INTO users (id, full_name, email, password, role, phone, is_active) VALUES
(2, 'Property Manager', 'manager@rentsys.com', '$2y$10$eJWk/l7sryclAQkGu0EFze.9r8mAaCrpxhhTtIG5yJGHCvIzAReme', 'Manager', '0711000002', 1),
(3, 'Front Desk Staff', 'staff@rentsys.com', '$2y$10$xICMdmyps6wXR1h6B1B.IuG8eoFMA0D9aEUc0kFgl17rTvAJoQDtm', 'Staff', '0711000003', 1);
