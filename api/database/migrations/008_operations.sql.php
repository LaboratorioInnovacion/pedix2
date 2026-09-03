<?php declare(strict_types=1);
use VO\Database\Connection;

return static function (Connection $db): void {
    foreach ([
        "ALTER TABLE stock_movements MODIFY COLUMN reason ENUM('reserve','release_cancelled','release_rejected','release_expired','consume_accepted','adjustment') NOT NULL",
        // MariaDB 10.x: DROP CHECK is MySQL 8 syntax; DROP CONSTRAINT is the supported form for CHECK constraints.
        "ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movements_reason",
        "ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_reason CHECK(reason IN ('reserve','release_cancelled','release_rejected','release_expired','consume_accepted','adjustment'))",
    ] as $sql) $db->execute($sql);
};
