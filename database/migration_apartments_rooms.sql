USE rentsys;

CREATE TABLE IF NOT EXISTS apartments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  city VARCHAR(100) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  total_floors INT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_apartments_active (is_active),
  INDEX idx_apartments_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT IGNORE INTO apartments (id, name, address, city, description, total_floors) VALUES
(1, 'Sunrise Apartments', '12 Kenyatta Avenue', 'Nairobi', 'Modern apartments near the city centre', 5),
(2, 'Parkview Residences', '45 Moi Road', 'Nairobi', 'Quiet residential block with parking', 4),
(3, 'Green Court', '8 Uhuru Highway', 'Mombasa', 'Coastal apartments with sea view', 3);

INSERT IGNORE INTO rooms (id, apartment_id, room_number, floor, rent_amount, bedrooms, bathrooms, status) VALUES
(1, 1, 'A-01', 1, 18000.00, 1, 1, 'occupied'),
(2, 1, 'A-02', 1, 18000.00, 1, 1, 'available'),
(3, 1, 'B-12', 2, 25000.00, 2, 1, 'occupied'),
(4, 2, 'C-07', 1, 22000.00, 2, 2, 'available'),
(5, 2, 'D-02', 2, 30000.00, 3, 2, 'occupied'),
(6, 3, 'E-05', 1, 15000.00, 1, 1, 'maintenance');
