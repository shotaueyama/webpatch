CREATE TABLE IF NOT EXISTS webpatch_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY password_reset_tokens_token_hash_unique (token_hash),
  KEY webpatch_password_reset_tokens_user_id_index (user_id),
  CONSTRAINT webpatch_password_reset_tokens_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(12) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  entry_file VARCHAR(500) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  source_type VARCHAR(20) NOT NULL DEFAULT 'zip',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_projects_public_id_unique (public_id),
  KEY webpatch_projects_user_id_index (user_id),
  CONSTRAINT webpatch_projects_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_project_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('comment','edit') NOT NULL DEFAULT 'comment',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_project_shares_project_user_unique (project_id, user_id),
  KEY webpatch_project_shares_user_id_index (user_id),
  KEY webpatch_project_shares_created_by_index (created_by),
  CONSTRAINT webpatch_project_shares_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_project_shares_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_project_shares_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_project_git_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  repository_url VARCHAR(500) NOT NULL DEFAULT '',
  branch_name VARCHAR(120) NOT NULL DEFAULT 'main',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_project_git_settings_project_id_unique (project_id),
  KEY webpatch_project_git_settings_project_id_index (project_id),
  CONSTRAINT webpatch_project_git_settings_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_project_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  role ENUM('comment','edit') NOT NULL DEFAULT 'comment',
  created_by BIGINT UNSIGNED NOT NULL,
  accepted_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_project_invites_token_hash_unique (token_hash),
  KEY webpatch_project_invites_project_id_index (project_id),
  KEY webpatch_project_invites_email_index (email),
  KEY webpatch_project_invites_created_by_index (created_by),
  CONSTRAINT webpatch_project_invites_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_project_invites_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_project_public_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  token CHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_project_public_links_project_unique (project_id),
  UNIQUE KEY webpatch_project_public_links_token_unique (token),
  KEY webpatch_project_public_links_created_by_index (created_by),
  CONSTRAINT webpatch_project_public_links_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_project_public_links_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  selector VARCHAR(255) NULL,
  viewport_mode VARCHAR(16) NULL,
  user_id BIGINT UNSIGNED NULL,
  guest_name VARCHAR(120) NULL,
  guest_key VARCHAR(80) NULL,
  parent_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  resolved_at DATETIME NULL,
  sheet_status VARCHAR(20) NOT NULL DEFAULT 'todo',
  desired_due_at DATETIME NULL,
  ai_check_status VARCHAR(24) NOT NULL DEFAULT 'unchecked',
  ai_check_summary TEXT NULL,
  ai_checked_at DATETIME NULL,
  ai_check_provider VARCHAR(32) NULL,
  ai_check_model VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY webpatch_comments_project_file_index (project_id, file_path),
  KEY webpatch_comments_user_id_index (user_id),
  KEY webpatch_comments_parent_id_index (parent_id),
  CONSTRAINT webpatch_comments_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_comments_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_comments_parent_id_foreign
    FOREIGN KEY (parent_id) REFERENCES webpatch_comments (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_ai_check_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(32) NOT NULL,
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'queued',
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  counts_json TEXT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  UNIQUE KEY uniq_public_id (public_id),
  KEY idx_project_user (project_id, user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_ai_user_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  ai_check_provider VARCHAR(32) NOT NULL DEFAULT 'openai',
  app_language VARCHAR(8) NOT NULL DEFAULT 'ja',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_comment_thread_reads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  thread_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_comment_thread_reads_thread_user_unique (thread_id, user_id),
  KEY webpatch_comment_thread_reads_project_user_index (project_id, user_id),
  KEY webpatch_comment_thread_reads_thread_id_index (thread_id),
  CONSTRAINT webpatch_comment_thread_reads_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_comment_thread_reads_thread_id_foreign
    FOREIGN KEY (thread_id) REFERENCES webpatch_comments (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_comment_thread_reads_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_comment_images (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  comment_id BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY webpatch_comment_images_project_id_index (project_id),
  KEY webpatch_comment_images_comment_id_index (comment_id),
  CONSTRAINT webpatch_comment_images_project_id_foreign
    FOREIGN KEY (project_id) REFERENCES webpatch_projects (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_comment_images_comment_id_foreign
    FOREIGN KEY (comment_id) REFERENCES webpatch_comments (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(12) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  markdown MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_notes_public_id_unique (public_id),
  KEY webpatch_notes_user_id_index (user_id),
  CONSTRAINT webpatch_notes_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_note_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_note_shares_note_user_unique (note_id, user_id),
  KEY webpatch_note_shares_user_id_index (user_id),
  KEY webpatch_note_shares_created_by_index (created_by),
  CONSTRAINT webpatch_note_shares_note_id_foreign
    FOREIGN KEY (note_id) REFERENCES webpatch_notes (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_note_shares_user_id_foreign
    FOREIGN KEY (user_id) REFERENCES webpatch_users (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_note_shares_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_note_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  accepted_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_note_invites_token_hash_unique (token_hash),
  KEY webpatch_note_invites_note_id_index (note_id),
  KEY webpatch_note_invites_email_index (email),
  KEY webpatch_note_invites_created_by_index (created_by),
  CONSTRAINT webpatch_note_invites_note_id_foreign
    FOREIGN KEY (note_id) REFERENCES webpatch_notes (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_note_invites_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpatch_note_public_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id BIGINT UNSIGNED NOT NULL,
  token CHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY webpatch_note_public_links_note_unique (note_id),
  UNIQUE KEY webpatch_note_public_links_token_unique (token),
  KEY webpatch_note_public_links_created_by_index (created_by),
  CONSTRAINT webpatch_note_public_links_note_id_foreign
    FOREIGN KEY (note_id) REFERENCES webpatch_notes (id)
    ON DELETE CASCADE,
  CONSTRAINT webpatch_note_public_links_created_by_foreign
    FOREIGN KEY (created_by) REFERENCES webpatch_users (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
