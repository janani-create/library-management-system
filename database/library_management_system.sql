-- =======================================================
-- Library Management System Database Export / Schema
-- Ready for phpMyAdmin import (XAMPP)
-- =======================================================

CREATE DATABASE IF NOT EXISTS library_management_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE library_management_system;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'librarian', 'member') NOT NULL DEFAULT 'member',
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB;

-- 2. Categories Table
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Books Table
CREATE TABLE IF NOT EXISTS books (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(190) NOT NULL,
  isbn VARCHAR(30) NULL UNIQUE,
  publisher VARCHAR(190) NULL,
  published_year SMALLINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  available_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  rack_no VARCHAR(50) NULL,
  cover_image VARCHAR(255) NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_books_category
    FOREIGN KEY (category_id) REFERENCES categories(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_books_title (title),
  INDEX idx_books_author (author),
  INDEX idx_books_isbn (isbn),
  INDEX idx_books_category (category_id)
) ENGINE=InnoDB;

-- 4. Members Table
CREATE TABLE IF NOT EXISTS members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_no VARCHAR(30) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  address TEXT NULL,
  status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_members_no (member_no),
  INDEX idx_members_email (email)
) ENGINE=InnoDB;

-- 5. Book Issues Table
CREATE TABLE IF NOT EXISTS book_issues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  issued_by INT UNSIGNED NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  return_date DATE NULL,
  status ENUM('issued', 'returned', 'overdue') NOT NULL DEFAULT 'issued',
  fine DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_issue_member
    FOREIGN KEY (member_id) REFERENCES members(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_issue_book
    FOREIGN KEY (book_id) REFERENCES books(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_issue_user
    FOREIGN KEY (issued_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_issue_status (status),
  INDEX idx_issue_due_date (due_date),
  INDEX idx_issue_member (member_id),
  INDEX idx_issue_book (book_id)
) ENGINE=InnoDB;

-- 6. Book Returns Table
CREATE TABLE IF NOT EXISTS book_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id INT UNSIGNED NOT NULL,
  received_by INT UNSIGNED NULL,
  return_date DATE NOT NULL,
  overdue_days INT UNSIGNED NOT NULL DEFAULT 0,
  fine_collected DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_return_issue
    FOREIGN KEY (issue_id) REFERENCES book_issues(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_return_user
    FOREIGN KEY (received_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_return_date (return_date)
) ENGINE=InnoDB;

-- Seed Initial Demo Data
INSERT IGNORE INTO users (id, name, email, password, role, status) VALUES
(1, 'System Administrator', 'admin@library.com', '$2y$10$/8jHoVE1VPvsNw6xvBa5F.0gdrodpqz6NtDpK117esMUh7jXH430a', 'admin', 'active'),
(2, 'Sarah Jenkins', 'librarian@library.com', '$2y$10$7Z/Y9eP43L70Rfgw8kO/2.zP5bQc7Xp5fK.J4i5YkQeR2y9dOm.eq', 'librarian', 'active');

INSERT IGNORE INTO categories (id, category_name, description) VALUES
(1, 'Computer Science & IT', 'Programming, Artificial Intelligence, Databases, and Software Engineering'),
(2, 'Fiction & Literature', 'Novels, classic literature, drama, and contemporary short stories'),
(3, 'Science & Nature', 'Physics, Chemistry, Biology, Astronomy, and Natural Sciences'),
(4, 'Business & Economics', 'Management, Finance, Entrepreneurship, and Marketing'),
(5, 'History & Biography', 'World history, civilizations, and inspirational biographies');

INSERT IGNORE INTO books (id, category_id, title, author, isbn, publisher, published_year, quantity, available_quantity, rack_no, description) VALUES
(1, 1, 'Clean Code: A Handbook of Agile Software Craftsmanship', 'Robert C. Martin', '978-0132350884', 'Prentice Hall', 2008, 5, 4, 'Rack A-1', 'Even bad code can function. But if code isn’t clean, it can bring a development organization to its knees.'),
(2, 1, 'Design Patterns: Elements of Reusable Object-Oriented Software', 'Erich Gamma, Richard Helm, Ralph Johnson, John Vlissides', '978-0201633610', 'Addison-Wesley', 1994, 4, 3, 'Rack A-2', 'Capturing a wealth of experience about the design of object-oriented software.'),
(3, 2, 'To Kill a Mockingbird', 'Harper Lee', '978-0061120084', 'Harper Perennial', 1960, 6, 5, 'Rack B-1', 'A gripping, heart-wrenching, and wholly remarkable tale of coming-of-age in a South poisoned by virulent prejudice.'),
(4, 2, '1984', 'George Orwell', '978-0451524935', 'Signet Classic', 1949, 5, 4, 'Rack B-2', 'A dystopian social science fiction novel and cautionary tale about totalitarianism.'),
(5, 3, 'A Brief History of Time', 'Stephen Hawking', '978-0553380163', 'Bantam Books', 1988, 4, 4, 'Rack C-1', 'From the Big Bang to Black Holes - exploring the cosmos and space-time.'),
(6, 4, 'The Lean Startup', 'Eric Ries', '978-0307887894', 'Crown Business', 2011, 5, 4, 'Rack D-1', 'How Today’s Entrepreneurs Use Continuous Innovation to Create Radically Successful Businesses.'),
(7, 5, 'Sapiens: A Brief History of Humankind', 'Yuval Noah Harari', '978-0062316097', 'Harper', 2014, 6, 5, 'Rack E-1', 'A renowned historian explores how humankind came to believe in gods, nations, and human rights.');

INSERT IGNORE INTO members (id, member_no, full_name, email, phone, address, status) VALUES
(1, 'MEM-1001', 'Kasun Perera', 'kasun.p@example.com', '+94 77 123 4567', 'No. 45, Galle Road, Colombo 03', 'active'),
(2, 'MEM-1002', 'Nisansala Silva', 'nisansala.s@example.com', '+94 71 987 6543', '12/A, Kandy Road, Kiribathgoda', 'active'),
(3, 'MEM-1003', 'Dulshan Fernando', 'dulshan.f@example.com', '+94 76 555 1234', '88 Temple Road, Maharagama', 'active'),
(4, 'MEM-1004', 'Anuki Wijesinghe', 'anuki.w@example.com', '+94 72 333 4455', '30 Flower Road, Colombo 07', 'active');

INSERT IGNORE INTO book_issues (id, member_id, book_id, issued_by, issue_date, due_date, return_date, status, fine, remarks) VALUES
(1, 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY), NULL, 'issued', 0.00, 'Regular lending'),
(2, 2, 3, 1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), NULL, 'overdue', 300.00, '6 days overdue'),
(3, 3, 4, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), NULL, 'overdue', 50.00, '1 day overdue'),
(4, 4, 2, 2, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY), NULL, 'issued', 0.00, 'Exam preparation'),
(5, 1, 6, 1, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'returned', 50.00, 'Returned 1 day late with fine paid');

INSERT IGNORE INTO book_returns (id, issue_id, received_by, return_date, overdue_days, fine_collected, remarks) VALUES
(1, 5, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 1, 50.00, 'Paid in cash at library counter');
