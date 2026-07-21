-- Date fields for rooms/tenants and report schedules
USE rentsys;

ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS registered_date DATE DEFAULT NULL AFTER notes;

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS listed_date DATE DEFAULT NULL AFTER description;

CREATE TABLE IF NOT EXISTS report_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  entity ENUM('apartments', 'rooms', 'tenants', 'leases', 'payments', 'maintenance') NOT NULL,
  period ENUM('daily', 'weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
  created_by INT UNSIGNED DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_report_schedules_entity (entity),
  INDEX idx_report_schedules_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
