<?php
// Persistence for public WebAuthn credentials. Private keys never reach the server.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../WebAuthn.php';

class Passkey {
    public static function allForUser(int $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, credential_id, user_handle, transports, label, backup_eligible, backed_up, created_at, last_used_at FROM passkeys WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
        $stmt->execute(['user_id' => $userId]);
        return array_map([self::class, 'publicRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function descriptorsForUser(int $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT credential_id, transports FROM passkeys WHERE user_id = :user_id ORDER BY id');
        $stmt->execute(['user_id' => $userId]);
        $descriptors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transports = json_decode((string)($row['transports'] ?? ''), true);
            $descriptor = ['type' => 'public-key', 'id' => $row['credential_id']];
            if (is_array($transports) && $transports) $descriptor['transports'] = array_values($transports);
            $descriptors[] = $descriptor;
        }
        return $descriptors;
    }

    public static function userHandleForUser(int $userId): string {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT user_handle FROM passkeys WHERE user_id = :user_id ORDER BY id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $handle = $stmt->fetchColumn();
        return $handle !== false && $handle !== '' ? (string)$handle : WebAuthn::base64urlEncode(random_bytes(32));
    }

    public static function create(int $userId, string $userHandle, array $verified, array $payload, string $label): int {
        $db = Database::getConnection();
        $transports = $payload['response']['transports'] ?? [];
        $allowedTransports = ['ble', 'cable', 'hybrid', 'internal', 'nfc', 'smart-card', 'usb'];
        $transports = is_array($transports) ? array_values(array_intersect($allowedTransports, $transports)) : [];
        $label = trim($label);
        if ($label === '') $label = 'Passkey';
        $label = substr($label, 0, 100);
        $stmt = $db->prepare('INSERT INTO passkeys (user_id, credential_id, credential_id_hash, user_handle, public_key, sign_count, transports, label, backup_eligible, backed_up) VALUES (:user_id, :credential_id, :credential_id_hash, :user_handle, :public_key, :sign_count, :transports, :label, :backup_eligible, :backed_up)');
        $stmt->execute([
            'user_id' => $userId,
            'credential_id' => $verified['credential_id'],
            'credential_id_hash' => hash('sha256', $verified['credential_id']),
            'user_handle' => $userHandle,
            'public_key' => $verified['public_key'],
            'sign_count' => $verified['sign_count'],
            'transports' => json_encode($transports),
            'label' => $label,
            'backup_eligible' => $verified['backup_eligible'],
            'backed_up' => $verified['backed_up'],
        ]);
        return (int)$db->lastInsertId();
    }

    public static function findByCredentialId(string $credentialId): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT p.*, u.username FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.credential_id_hash = :credential_id_hash LIMIT 1');
        $stmt->execute(['credential_id_hash' => hash('sha256', $credentialId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['credential_id'], $credentialId)) return null;
        return $row;
    }

    public static function recordUse(int $id, int $oldCount, int $newCount, int $backedUp): bool {
        $db = Database::getConnection();
        if ($oldCount > 0 && $newCount > 0) {
            $stmt = $db->prepare('UPDATE passkeys SET sign_count = :new_count, backed_up = :backed_up, last_used_at = CURRENT_TIMESTAMP WHERE id = :id AND sign_count = :old_count');
            $stmt->execute(['new_count' => $newCount, 'backed_up' => $backedUp, 'id' => $id, 'old_count' => $oldCount]);
            return $stmt->rowCount() === 1;
        }
        $stmt = $db->prepare('UPDATE passkeys SET sign_count = :new_count, backed_up = :backed_up, last_used_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['new_count' => max($oldCount, $newCount), 'backed_up' => $backedUp, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public static function deleteForUser(int $id, int $userId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM passkeys WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    private static function publicRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'label' => (string)$row['label'],
            'transports' => json_decode((string)($row['transports'] ?? ''), true) ?: [],
            'backup_eligible' => !empty($row['backup_eligible']),
            'backed_up' => !empty($row['backed_up']),
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
        ];
    }
}
?>
