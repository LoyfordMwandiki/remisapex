USE rentsys;

CREATE TABLE IF NOT EXISTS maintenance_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT DEFAULT NULL,
  priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  status ENUM('open', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
  reported_date DATE NOT NULL,
  completed_date DATE DEFAULT NULL,
  cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  assigned_to VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_maintenance_status (status),
  INDEX idx_maintenance_priority (priority),
  INDEX idx_maintenance_apartment (apartment_id),
  CONSTRAINT fk_maintenance_apartment
    FOREIGN KEY (apartment_id) REFERENCES apartments(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_maintenance_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('company_name', 'RentSys Pro'),
('company_email', 'admin@rentsys.com'),
('company_phone', '+254 700 000 000'),
('company_address', 'Nairobi, Kenya'),
('currency', 'KES'),
('currency_symbol', 'KES'),
('rent_due_day', '5'),
('late_fee_percent', '5');

INSERT IGNORE INTO maintenance_requests (id, apartment_id, room_id, title, description, priority, status, reported_date, cost, assigned_to) VALUES
(1, 1, 2, 'Leaking kitchen sink', 'Water dripping under the sink cabinet', 'high', 'open', '2026-07-10', 0.00, 'Plumber Joe'),
(2, 2, 5, 'Broken door lock', 'Main door lock needs replacement', 'urgent', 'in_progress', '2026-07-12', 3500.00, 'Locksmith Ltd'),
(3, 3, 6, 'Paint touch-up', 'Corridor walls need repainting', 'low', 'completed', '2026-06-20', 8000.00, 'Paint Pros'),
(4, 1, NULL, 'Generator servicing', 'Quarterly generator maintenance for common areas', 'medium', 'open', '2026-07-14', 0.00, NULL);
