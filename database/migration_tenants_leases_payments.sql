USE rentsys;

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
