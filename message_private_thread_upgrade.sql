-- Private messaging upgrade
-- Run in phpMyAdmin before using DJ replies / guest mute-block controls.

CREATE TABLE IF NOT EXISTS message_guest_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  guest_token VARCHAR(64) NOT NULL,
  status ENUM('active','muted','blocked') NOT NULL DEFAULT 'active',
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_guest (event_id, guest_token),
  KEY idx_event_status (event_id, status),
  KEY idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  guest_token VARCHAR(64) NOT NULL,
  dj_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_guest_created (event_id, guest_token, created_at),
  KEY idx_dj_created (dj_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
