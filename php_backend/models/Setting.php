<?php
// Simple key-value settings storage.
require_once __DIR__ . '/../Database.php';

class Setting {
    private const DEFAULT_SITE_NAME = 'Finance Manager';
    private const DEFAULT_COLOR_SCHEME = 'indigo';

    /**
     * Retrieve a setting value by name.
     */
    public static function get(string $name): ?string {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `value` FROM `settings` WHERE `name` = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : null;
    }

    /**
     * Store a setting value, updating existing entries.
     */
    public static function set(string $name, string $value): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `settings` (`name`, `value`) VALUES (:name, :value)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        $stmt->execute(['name' => $name, 'value' => $value]);
    }

    /**
     * Retrieve branding settings such as site name and color scheme.
     *
     * @return array{site_name: string, color_scheme: string}
     */
    public static function getBrand(): array {
        return [
            'site_name'    => self::get('site_name') ?? self::DEFAULT_SITE_NAME,
            'color_scheme' => self::get('color_scheme') ?? self::DEFAULT_COLOR_SCHEME,
        ];
    }
}
?>
