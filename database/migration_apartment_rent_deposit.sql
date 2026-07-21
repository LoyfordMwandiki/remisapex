USE rentsys;

ALTER TABLE apartments
  ADD COLUMN IF NOT EXISTS rent_deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_floors;
