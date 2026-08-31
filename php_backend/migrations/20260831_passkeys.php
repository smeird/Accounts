<?php
// Adds discoverable WebAuthn credentials without changing existing login data.
require_once __DIR__ . '/../Database.php';

$db = Database::getConnection();
$db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS passkeys (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id TEXT NOT NULL,
    credential_id_hash CHAR(64) NOT NULL UNIQUE,
    user_handle VARCHAR(86) NOT NULL,
    public_key TEXT NOT NULL,
    sign_count BIGINT NOT NULL DEFAULT 0,
    transports VARCHAR(255) DEFAULT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    backup_eligible TINYINT(1) NOT NULL DEFAULT 0,
    backed_up TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_passkeys_user (user_id),
    CONSTRAINT fk_passkeys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
);

echo "Passkey credential table is ready.\n";
?>
