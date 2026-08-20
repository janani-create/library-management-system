USE library_management_system;

CREATE TABLE IF NOT EXISTS membership_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  monthly_fee DECIMAL(10,2) NOT NULL,
  borrowing_limit INT UNSIGNED NOT NULL DEFAULT 3,
  loan_days INT UNSIGNED NOT NULL DEFAULT 14,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO membership_plans (id, name, monthly_fee, borrowing_limit, loan_days) VALUES
(1, 'Standard', 500.00, 3, 14),
(2, 'Student', 300.00, 2, 14),
(3, 'Premium', 1000.00, 6, 30);

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS membership_plan_id INT UNSIGNED NULL AFTER address,
  ADD COLUMN IF NOT EXISTS membership_expires_at DATE NULL AFTER membership_plan_id;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND CONSTRAINT_NAME = 'fk_member_plan');
SET @fk_sql = IF(@fk_exists = 0, 'ALTER TABLE members ADD CONSTRAINT fk_member_plan FOREIGN KEY (membership_plan_id) REFERENCES membership_plans(id) ON UPDATE CASCADE ON DELETE SET NULL', 'SELECT 1');
PREPARE fk_stmt FROM @fk_sql; EXECUTE fk_stmt; DEALLOCATE PREPARE fk_stmt;

CREATE TABLE IF NOT EXISTS membership_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  membership_plan_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  months_paid INT UNSIGNED NOT NULL DEFAULT 1,
  payment_date DATE NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  payment_method ENUM('cash','card','bank_transfer') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(50) NOT NULL UNIQUE,
  received_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_member FOREIGN KEY (member_id) REFERENCES members(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_plan FOREIGN KEY (membership_plan_id) REFERENCES membership_plans(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_user FOREIGN KEY (received_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_payment_member (member_id), INDEX idx_payment_date (payment_date), INDEX idx_payment_period_end (period_end)
) ENGINE=InnoDB;
