-- MoneyMentor database schema (MySQL 8 / MariaDB 10.6+)
-- Safe to run more than once. bin/migrate.php runs this automatically on start-up.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  last_login DATETIME NULL,
  password_changed_at DATETIME NULL,         -- tokens issued before this stop working
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
  reply TEXT NULL,
  replied_at DATETIME NULL,
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

-- ===== Learner features =====

-- One row per lesson a user has finished
CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id INT UNSIGNED NOT NULL,
  module_id VARCHAR(40) NOT NULL,
  lesson_id VARCHAR(60) NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module_id, lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every module quiz attempt
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  module_id VARCHAR(40) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  total TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quiz_user (user_id, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each user's saved budget plan (JSON)
CREATE TABLE IF NOT EXISTS budgets (
  user_id INT UNSIGNED PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Articles a user bookmarked
CREATE TABLE IF NOT EXISTS saved_articles (
  user_id INT UNSIGNED NOT NULL,
  article_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Knowledge check (personalised learning path) =====
CREATE TABLE IF NOT EXISTS assessments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  total TINYINT UNSIGNED NOT NULL,
  level VARCHAR(20) NOT NULL,
  topic_scores TEXT NOT NULL,       -- JSON: {"security": 2, "saving": 1, ...}
  goals TEXT NOT NULL,              -- JSON: ["security", "saving"]
  path TEXT NOT NULL,               -- JSON: recommended module order, key lessons
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assess_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Scam alerts (published by admins, shown on the home page) =====
CREATE TABLE IF NOT EXISTS scam_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  summary VARCHAR(400) NOT NULL,
  red_flags TEXT NOT NULL,          -- one red flag per line
  what_to_do TEXT NOT NULL,         -- one step per line
  severity ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
  status ENUM('draft', 'published') NOT NULL DEFAULT 'published',
  summary_pcm TEXT NULL,            -- Pidgin version of the summary (optional)
  emailed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_alerts_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Password reset links (token stored hashed, valid 1 hour) =====
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Scams reported by visitors (reviewed by admins, can become alerts) =====
CREATE TABLE IF NOT EXISTS scam_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  channel VARCHAR(20) NOT NULL,             -- call, sms, whatsapp, email, social, website, in_person, other
  scammer_contact VARCHAR(190) NOT NULL DEFAULT '',  -- number, account, link or handle they used
  description TEXT NOT NULL,
  lost_money TINYINT(1) NOT NULL DEFAULT 0,
  amount_lost DECIMAL(14,2) NULL,
  status ENUM('new', 'reviewed', 'published', 'rejected') NOT NULL DEFAULT 'new',
  alert_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reports_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Who to call: bank fraud lines and agencies (managed by admins) =====
CREATE TABLE IF NOT EXISTS help_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('bank', 'agency') NOT NULL DEFAULT 'bank',
  ussd VARCHAR(40) NOT NULL DEFAULT '',     -- code to block the account, e.g. *123*911#
  phone VARCHAR(120) NOT NULL DEFAULT '',
  url VARCHAR(300) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_help_status (status, kind, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Expense tracking (actual spending, compared with the budget plan) =====
CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  category VARCHAR(80) NOT NULL,
  type ENUM('need', 'want', 'saving') NOT NULL DEFAULT 'need',
  note VARCHAR(200) NOT NULL DEFAULT '',
  spent_on DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expenses_user (user_id, spent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Ask an expert (answered by admins; good answers become a public FAQ) =====
CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  topic VARCHAR(30) NOT NULL DEFAULT 'other',
  question TEXT NOT NULL,
  answer TEXT NULL,
  status ENUM('pending', 'answered') NOT NULL DEFAULT 'pending',
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  answered_by INT UNSIGNED NULL,
  answered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_questions_user (user_id),
  INDEX idx_questions_status (status, is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Optional YouTube video for a lesson =====
CREATE TABLE IF NOT EXISTS lesson_videos (
  module_id VARCHAR(40) NOT NULL,
  lesson_id VARCHAR(60) NOT NULL,
  youtube_id VARCHAR(20) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (module_id, lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
