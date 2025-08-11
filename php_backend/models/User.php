<?php
// Model for application users with password authentication.
require_once __DIR__ . '/../Database.php';

class User {
    /**
     * Create a new user with the given username and password.
     */
    public static function create(string $username, string $password): int {
        $db = Database::getConnection();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO `users` (`username`, `password`) VALUES (:username, :password)');
        $stmt->execute(['username' => $username, 'password' => $hash]);
        return (int)$db->lastInsertId();
    }

    /**
     * Look up a user record by username.
     */
    public static function findByUsername(string $username): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id`, `username`, `password` FROM `users` WHERE `username` = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    /**
     * Verify a username and password, returning the user ID or null on failure.
     */
    public static function verify(string $username, string $password, ?string &$reason = null): ?int {
        $user = self::findByUsername($username);
        if (!$user) {
            $reason = 'user not found';
            return null;
        }
        if (password_verify($password, $user['password'])) {
            return (int)$user['id'];
        }
        $reason = 'password mismatch';

        return null;
    }

    /**
     * Update the password for the specified user ID.
     */
    public static function updatePassword(int $id, string $password): bool {
        $db = Database::getConnection();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE `users` SET `password` = :password WHERE `id` = :id');
        return $stmt->execute(['password' => $hash, 'id' => $id]);
    }
}
?>
