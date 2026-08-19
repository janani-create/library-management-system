USE library_management_system;

ALTER TABLE users CHANGE COLUMN full_name name VARCHAR(100) NOT NULL;
ALTER TABLE books CHANGE COLUMN total_copies quantity INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE books CHANGE COLUMN available_copies available_quantity INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE categories CHANGE COLUMN name category_name VARCHAR(100) NOT NULL;

CREATE TABLE IF NOT EXISTS members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_no VARCHAR(30) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  address TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_issues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  return_date DATE NULL,
  status ENUM('issued','returned','overdue') NOT NULL DEFAULT 'issued',
  fine DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_issue_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
  CONSTRAINT fk_issue_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
  INDEX idx_issue_status (status), INDEX idx_issue_due (due_date)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (name,email,password,role) VALUES
('System Administrator','admin@library.com','$2y$10$/8jHoVE1VPvsNw6xvBa5F.0gdrodpqz6NtDpK117esMUh7jXH430a','admin');
