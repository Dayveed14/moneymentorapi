-- MoneyMentor database schema (MySQL 8 / MariaDB 10.6+)
-- Safe to run more than once. bin/migrate.php runs this automatically on start-up.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  summary VARCHAR(300) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  category VARCHAR(50) NOT NULL,
  image_path VARCHAR(500) NOT NULL DEFAULT '',
  image_public_id VARCHAR(255) NULL,          -- Cloudinary id, used to delete the image
  author_id INT UNSIGNED NULL,
  status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
  published_date DATETIME NULL,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_articles_listing (status, published_date),
  INDEX idx_articles_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Article images stored in the DB — only used when Cloudinary isn't configured (local dev)
CREATE TABLE IF NOT EXISTS article_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mime VARCHAR(30) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  ip_address VARCHAR(45) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Used for login / form throttling
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket CHAR(64) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
