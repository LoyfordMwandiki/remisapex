USE rentsys;

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
