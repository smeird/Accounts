<?php
// Simple key-value settings storage.
require_once __DIR__ . '/../Database.php';

class Setting {
    /** Default fonts used when none have been configured. */
    private const DEFAULT_HEADING_FONT = 'Roboto';
    private const DEFAULT_BODY_FONT = 'Inter';
    private const DEFAULT_ACCENT_FONT = 'Source Sans Pro';

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
     * Convenience accessor for the site's configured fonts with sensible defaults.
     *
     * @return array{heading: string, body: string, accent: string}
     */
    public static function getFonts(): array {
        return [
            'heading' => self::get('font_heading') ?? self::DEFAULT_HEADING_FONT,
            'body'    => self::get('font_body') ?? self::DEFAULT_BODY_FONT,
            'accent'  => self::get('font_accent') ?? self::DEFAULT_ACCENT_FONT,
        ];
    }
}
?>
