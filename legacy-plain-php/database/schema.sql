CREATE DATABASE IF NOT EXISTS library_management_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE library_management_system;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'librarian', 'member') NOT NULL DEFAULT 'member',
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS books (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(190) NOT NULL,
  isbn VARCHAR(20) NULL UNIQUE,
  publisher VARCHAR(190) NULL,
  published_year SMALLINT UNSIGNED NULL,
  total_copies INT UNSIGNED NOT NULL DEFAULT 1,
  available_copies INT UNSIGNED NOT NULL DEFAULT 1,
  cover_image VARCHAR(255) NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_books_category
    FOREIGN KEY (category_id) REFERENCES categories(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_books_title (title),
  INDEX idx_books_author (author),
  INDEX idx_books_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NOT NULL,
  returned_at DATETIME NULL,
  status ENUM('borrowed', 'returned', 'overdue') NOT NULL DEFAULT 'borrowed',
  fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_loans_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_loans_book
    FOREIGN KEY (book_id) REFERENCES books(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_loans_user (user_id),
  INDEX idx_loans_book (book_id),
  INDEX idx_loans_status (status),
  INDEX idx_loans_due_at (due_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (name, description) VALUES
  ('Fiction', 'Novels, short stories, and literary fiction'),
  ('Science', 'Science, technology, and nature'),
  ('History', 'History, biography, and culture'),
  ('Education', 'Academic and educational resources');
