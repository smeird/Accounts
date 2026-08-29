<?php
// Simple key-value settings storage.
require_once __DIR__ . '/../Database.php';

class Setting {
    private const DEFAULT_SITE_NAME = 'Finance Manager';
    private const DEFAULT_COLOR_SCHEME = 'indigo';
    private const DEFAULT_FONT       = '';
    private const DEFAULT_SURFACE_STYLE = 'glass';
    private const DEFAULT_INTERFACE_DENSITY = 'comfortable';
    private const DEFAULT_CORNER_STYLE = 'soft';
    private const DEFAULT_BACKDROP_STRENGTH = 'balanced';
    private const DEFAULT_MOTION_PREFERENCE = 'standard';

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
        $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO `settings` (`name`, `value`) VALUES (:name, :value)
                ON CONFLICT(`name`) DO UPDATE SET `value` = excluded.`value`'
            : 'INSERT INTO `settings` (`name`, `value`) VALUES (:name, :value)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['name' => $name, 'value' => $value]);
    }

    /**
     * Retrieve branding settings such as site name, colour scheme and fonts.
     *
     * @return array{site_name: string, color_scheme: string, heading_font: string,
     *               body_font: string, table_font: string, chart_font: string,
     *               accent_font_weight: string, surface_style: string,
     *               interface_density: string, corner_style: string,
     *               backdrop_strength: string, motion_preference: string}
     */
    public static function getBrand(): array {
        $settings = self::all();
        $accent = $settings['accent_font_weight'] ?? null;
        if ($accent === null) {
            $legacyAccent = $settings['font_accent_weight'] ?? null;
            if ($legacyAccent !== null) {
                $accent = $legacyAccent;
                self::set('accent_font_weight', $legacyAccent);
            }
        }

        return [
            'site_name'    => $settings['site_name']    ?? self::DEFAULT_SITE_NAME,
            'color_scheme' => $settings['color_scheme'] ?? self::DEFAULT_COLOR_SCHEME,
            'heading_font' => $settings['font_heading'] ?? self::DEFAULT_FONT,
            'body_font'    => $settings['font_body']    ?? self::DEFAULT_FONT,
            'table_font'   => $settings['font_table']   ?? self::DEFAULT_FONT,
            'chart_font'   => $settings['font_chart']   ?? self::DEFAULT_FONT,
            'accent_font_weight' => $accent ?? '',
            'surface_style' => self::choice($settings, 'surface_style', ['glass', 'paper'], self::DEFAULT_SURFACE_STYLE),
            'interface_density' => self::choice($settings, 'interface_density', ['compact', 'comfortable', 'roomy'], self::DEFAULT_INTERFACE_DENSITY),
            'corner_style' => self::choice($settings, 'corner_style', ['soft', 'balanced', 'square'], self::DEFAULT_CORNER_STYLE),
            'backdrop_strength' => self::choice($settings, 'backdrop_strength', ['calm', 'balanced', 'vivid'], self::DEFAULT_BACKDROP_STRENGTH),
            'motion_preference' => self::choice($settings, 'motion_preference', ['standard', 'reduced'], self::DEFAULT_MOTION_PREFERENCE),
        ];
    }

    /**
     * Return a stored allowlisted appearance choice or its stable default.
     */
    private static function choice(array $settings, string $name, array $allowed, string $default): string {
        $value = $settings[$name] ?? null;
        return $value !== null && in_array($value, $allowed, true) ? $value : $default;
    }

    /** @return array<string,string> */
    private static function all(): array {
        $db = Database::getConnection();
        $rows = $db->query('SELECT `name`, `value` FROM `settings`')->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['name']] = (string)$row['value'];
        }
        return $settings;
    }
}
?>
