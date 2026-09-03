<?php declare(strict_types=1);
use VO\Database\Connection;

/**
 * Migration 010: notification_events outbox (vo-notifications, design DDL exact).
 * Additive only: no permission or settings seeds by design — disabled channels
 * never enqueue (absent settings key = OFF) and the admin log reuses the
 * existing settings.manage permission.
 */
return static function (Connection $db): void {
    $db->execute("CREATE TABLE notification_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,event VARCHAR(64) NOT NULL,channel ENUM('email','whatsapp') NOT NULL,recipient VARCHAR(190) NOT NULL,subject VARCHAR(190) NULL,context_json JSON NULL,state ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,KEY idx_notification_events_business_state (business_id,state),KEY idx_notification_events_event (event),KEY idx_notification_events_created_at (created_at),CONSTRAINT fk_notification_events_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
