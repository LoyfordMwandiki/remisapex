-- RentSys Pro Database Setup
-- Run via: mysql -u root < database/setup.sql

CREATE DATABASE IF NOT EXISTS rentsys
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rentsys;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('Super Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Manager',
  phone VARCHAR(20) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_email (email),
  INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin account (password: admin123)
INSERT INTO users (full_name, email, password, role)
VALUES (
  'Administrator',
  'admin@rentsys.com',
  '$2y$10$N0JXUVvQIcv9QS4ef2AuY.zSJvv2wQezvDk9qqj6JlfkN7iRYC902',
  'Super Admin'
)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

-- Apartments
CREATE TABLE IF NOT EXISTS apartments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  city VARCHAR(100) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  total_floors INT UNSIGNED NOT NULL DEFAULT 1,
  monthly_rent_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  rent_deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_apartments_active (is_active),
  INDEX idx_apartments_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rooms
CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  apartment_id INT UNSIGNED NOT NULL,
  room_number VARCHAR(20) NOT NULL,
  floor INT UNSIGNED NOT NULL DEFAULT 1,
  rent_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  bedrooms TINYINT UNSIGNED NOT NULL DEFAULT 1,
  bathrooms TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('available', 'occupied', 'maintenance') NOT NULL DEFAULT 'available',
  description TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_apartment_room (apartment_id, room_number),
  INDEX idx_rooms_status (status),
  INDEX idx_rooms_apartment (apartment_id),
  CONSTRAINT fk_rooms_apartment
    FOREIGN KEY (apartment_id) REFERENCES apartments(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample apartments
INSERT IGNORE INTO apartments (id, name, address, city, description, total_floors) VALUES
(1, 'Sunrise Apartments', '12 Kenyatta Avenue', 'Nairobi', 'Modern apartments near the city centre', 5),
(2, 'Parkview Residences', '45 Moi Road', 'Nairobi', 'Quiet residential block with parking', 4),
(3, 'Green Court', '8 Uhuru Highway', 'Mombasa', 'Coastal apartments with sea view', 3);

-- Sample rooms
INSERT IGNORE INTO rooms (id, apartment_id, room_number, floor, rent_amount, bedrooms, bathrooms, status) VALUES
(1, 1, 'A-01', 1, 18000.00, 1, 1, 'occupied'),
(2, 1, 'A-02', 1, 18000.00, 1, 1, 'available'),
(3, 1, 'B-12', 2, 25000.00, 2, 1, 'occupied'),
(4, 2, 'C-07', 1, 22000.00, 2, 2, 'available'),
(5, 2, 'D-02', 2, 30000.00, 3, 2, 'occupied'),
(6, 3, 'E-05', 1, 15000.00, 1, 1, 'maintenance');

-- Tenants
CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(20) NOT NULL,
  id_number VARCHAR(30) DEFAULT NULL,
  emergency_contact VARCHAR(100) DEFAULT NULL,
  emergency_phone VARCHAR(20) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenants_phone (phone),
  INDEX idx_tenants_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leases
CREATE TABLE IF NOT EXISTS leases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  monthly_rent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('active', 'expired', 'terminated') NOT NULL DEFAULT 'active',
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_leases_tenant (tenant_id),
  INDEX idx_leases_room (room_id),
  INDEX idx_leases_status (status),
  CONSTRAINT fk_leases_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_leases_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lease_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_date DATE NOT NULL,
  payment_method ENUM('cash', 'mpesa', 'bank', 'card') NOT NULL DEFAULT 'cash',
  status ENUM('paid', 'pending', 'overdue') NOT NULL DEFAULT 'paid',
  reference_number VARCHAR(50) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_payments_lease (lease_id),
  INDEX idx_payments_tenant (tenant_id),
  INDEX idx_payments_status (status),
  INDEX idx_payments_date (payment_date),
  CONSTRAINT fk_payments_lease
    FOREIGN KEY (lease_id) REFERENCES leases(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_payments_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenants (id, full_name, email, phone, id_number) VALUES
(1, 'Grace Wanjiku', 'grace@email.com', '0712345678', '30123456'),
(2, 'James Otieno', 'james@email.com', '0723456789', '30234567'),
(3, 'Daniel Kamau', 'daniel@email.com', '0734567890', '30345678'),
(4, 'Faith Njeri', 'faith@email.com', '0745678901', '30456789');

INSERT IGNORE INTO leases (id, tenant_id, room_id, start_date, end_date, monthly_rent, deposit_amount, status) VALUES
(1, 1, 3, '2025-01-01', '2026-12-31', 25000.00, 50000.00, 'active'),
(2, 2, 1, '2025-03-15', '2026-03-14', 18000.00, 36000.00, 'active'),
(3, 3, 5, '2024-06-01', '2025-05-31', 30000.00, 60000.00, 'active');

INSERT IGNORE INTO payments (id, lease_id, tenant_id, amount, payment_date, payment_method, status, reference_number) VALUES
(1, 1, 1, 25000.00, '2026-07-01', 'mpesa', 'paid', 'MPX123456'),
(2, 2, 2, 18000.00, '2026-07-05', 'bank', 'paid', 'BNK789012'),
(3, 1, 1, 25000.00, '2026-06-01', 'mpesa', 'paid', 'MPX654321'),
(4, 3, 3, 30000.00, '2026-06-10', 'cash', 'overdue', NULL),
(5, 2, 2, 18000.00, '2026-08-01', 'mpesa', 'pending', NULL);

-- Rent deposits
CREATE TABLE IF NOT EXISTS rent_deposits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lease_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  apartment_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  date_paid DATE NOT NULL,
  payment_method ENUM('cash', 'mpesa', 'bank', 'card') NOT NULL DEFAULT 'cash',
  reference_number VARCHAR(50) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rent_deposits_lease (lease_id),
  INDEX idx_rent_deposits_tenant (tenant_id),
  INDEX idx_rent_deposits_room (room_id),
  INDEX idx_rent_deposits_date (date_paid),
  CONSTRAINT fk_rent_deposits_lease FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_rent_deposits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_rent_deposits_apartment FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_rent_deposits_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance requests
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

-- App settings
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
