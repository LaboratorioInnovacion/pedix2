<?php declare(strict_types=1);
use VO\Database\Connection;

return static function (Connection $db): void {
    $db->execute("CREATE TABLE auth_sessions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sid_hash CHAR(64) NOT NULL, user_id BIGINT UNSIGNED NOT NULL, ip_hash CHAR(64) NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, UNIQUE KEY uq_auth_sessions_sid_hash (sid_hash), KEY idx_auth_sessions_user_revoked (user_id,revoked_at), KEY idx_auth_sessions_absolute_expires (absolute_expires_at), KEY idx_auth_sessions_last_seen (last_seen_at), CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute("CREATE TABLE login_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, identifier_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL, attempted_at DATETIME NOT NULL, success TINYINT(1) NOT NULL DEFAULT 0, KEY idx_login_attempts_lookup (identifier_hash,ip_hash,attempted_at), KEY idx_login_attempts_attempted_at (attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
