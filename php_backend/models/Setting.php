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
    private const DEFAULT_ACCENT_BAR_SIZE = 'medium';
    private const DEFAULT_PAGE_HEADER_SIZE = 'medium';

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
     * @return array{site_name: string, color_scheme: string, brand_color: string,
     *               brand_color_dark: string, heading_font: string,
     *               body_font: string, table_font: string, chart_font: string,
     *               accent_font_weight: string, surface_style: string,
     *               interface_density: string, corner_style: string,
     *               backdrop_strength: string, motion_preference: string,
     *               accent_bar_size: string, page_header_size: string}
     */
    public static function getBrand(): array {
        $settings = self::all();
        $palettes = self::colorPalettes();
        $colorScheme = $settings['color_scheme'] ?? self::DEFAULT_COLOR_SCHEME;
        if (!array_key_exists($colorScheme, $palettes)) {
            $colorScheme = self::DEFAULT_COLOR_SCHEME;
        }
        $palette = $palettes[$colorScheme];
        $fonts = self::fontOptions();
        $fontChoice = function (string $name) use ($settings, $fonts): string {
            $value = $settings[$name] ?? self::DEFAULT_FONT;
            return array_key_exists($value, $fonts) ? $value : self::DEFAULT_FONT;
        };
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
            'color_scheme' => $colorScheme,
            'brand_color' => $palette['primary'],
            'brand_color_dark' => $palette['secondary'],
            'heading_font' => $fontChoice('font_heading'),
            'body_font'    => $fontChoice('font_body'),
            'table_font'   => $fontChoice('font_table'),
            'chart_font'   => $fontChoice('font_chart'),
            'accent_font_weight' => $accent ?? '',
            'surface_style' => self::choice($settings, 'surface_style', ['glass', 'paper'], self::DEFAULT_SURFACE_STYLE),
            'interface_density' => self::choice($settings, 'interface_density', ['compact', 'comfortable', 'roomy'], self::DEFAULT_INTERFACE_DENSITY),
            'corner_style' => self::choice($settings, 'corner_style', ['soft', 'balanced', 'square'], self::DEFAULT_CORNER_STYLE),
            'backdrop_strength' => self::choice($settings, 'backdrop_strength', ['calm', 'balanced', 'vivid'], self::DEFAULT_BACKDROP_STRENGTH),
            'motion_preference' => self::choice($settings, 'motion_preference', ['standard', 'reduced'], self::DEFAULT_MOTION_PREFERENCE),
            'accent_bar_size' => self::choice($settings, 'accent_bar_size', ['hairline', 'small', 'medium', 'large'], self::DEFAULT_ACCENT_BAR_SIZE),
            'page_header_size' => self::choice($settings, 'page_header_size', ['small', 'medium', 'large'], self::DEFAULT_PAGE_HEADER_SIZE),
        ];
    }

    /** @return array<string,array{label:string,description:string,primary:string,secondary:string}> */
    public static function colorPalettes(): array {
        return [
            'indigo' => ['label' => 'Indigo', 'description' => 'Focused and familiar', 'primary' => '#4f46e5', 'secondary' => '#4338ca'],
            'blue' => ['label' => 'Clear Blue', 'description' => 'Dependable and direct', 'primary' => '#2563eb', 'secondary' => '#1d4ed8'],
            'green' => ['label' => 'Classic Green', 'description' => 'Positive and practical', 'primary' => '#16a34a', 'secondary' => '#15803d'],
            'emerald' => ['label' => 'Emerald', 'description' => 'Calm financial clarity', 'primary' => '#059669', 'secondary' => '#047857'],
            'forest' => ['label' => 'Forest', 'description' => 'Grounded and restrained', 'primary' => '#15803d', 'secondary' => '#166534'],
            'teal' => ['label' => 'Teal', 'description' => 'Balanced and composed', 'primary' => '#0d9488', 'secondary' => '#0f766e'],
            'cyan' => ['label' => 'Cyan', 'description' => 'Bright and modern', 'primary' => '#0891b2', 'secondary' => '#0e7490'],
            'ocean' => ['label' => 'Ocean', 'description' => 'Cyan flowing into blue', 'primary' => '#0891b2', 'secondary' => '#2563eb'],
            'midnight' => ['label' => 'Midnight', 'description' => 'Deep navy with blue light', 'primary' => '#1e40af', 'secondary' => '#0f172a'],
            'slate' => ['label' => 'Slate', 'description' => 'Quiet neutral professionalism', 'primary' => '#475569', 'secondary' => '#334155'],
            'graphite' => ['label' => 'Graphite', 'description' => 'Minimal and architectural', 'primary' => '#475569', 'secondary' => '#1e293b'],
            'purple' => ['label' => 'Purple', 'description' => 'Confident and expressive', 'primary' => '#9333ea', 'secondary' => '#7e22ce'],
            'plum' => ['label' => 'Plum', 'description' => 'Rich and editorial', 'primary' => '#a21caf', 'secondary' => '#701a75'],
            'violet-rose' => ['label' => 'Violet Rose', 'description' => 'Violet flowing into rose', 'primary' => '#8b5cf6', 'secondary' => '#be123c'],
            'rose' => ['label' => 'Rose', 'description' => 'Warm and contemporary', 'primary' => '#e11d48', 'secondary' => '#be123c'],
            'red' => ['label' => 'Signal Red', 'description' => 'Bold and decisive', 'primary' => '#dc2626', 'secondary' => '#b91c1c'],
            'orange' => ['label' => 'Orange', 'description' => 'Energetic and clear', 'primary' => '#ea580c', 'secondary' => '#c2410c'],
            'amber' => ['label' => 'Amber', 'description' => 'Warm without becoming loud', 'primary' => '#d97706', 'secondary' => '#b45309'],
            'sunset' => ['label' => 'Sunset', 'description' => 'Orange flowing into pink', 'primary' => '#f97316', 'secondary' => '#be185d'],
            'aurora' => ['label' => 'Aurora', 'description' => 'Violet flowing into teal', 'primary' => '#7c3aed', 'secondary' => '#0f766e'],
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function fontGroups(): array {
        return [
            'System & familiar' => [
                '' => 'Default', 'Arial' => 'Arial', 'Helvetica' => 'Helvetica',
                'Verdana' => 'Verdana', 'Trebuchet MS' => 'Trebuchet MS',
                'Georgia' => 'Georgia', 'Times New Roman' => 'Times New Roman',
                'Garamond' => 'Garamond', 'Courier New' => 'Courier New',
                'Comic Sans MS' => 'Comic Sans MS',
            ],
            'Modern sans serif' => [
                'Inter' => 'Inter', 'Atkinson Hyperlegible' => 'Atkinson Hyperlegible',
                'Lexend' => 'Lexend', 'Manrope' => 'Manrope', 'DM Sans' => 'DM Sans',
                'Work Sans' => 'Work Sans', 'Source Sans 3' => 'Source Sans 3',
                'IBM Plex Sans' => 'IBM Plex Sans', 'Noto Sans' => 'Noto Sans',
                'Nunito Sans' => 'Nunito Sans', 'Roboto' => 'Roboto',
                'Open Sans' => 'Open Sans', 'Lato' => 'Lato',
                'Montserrat' => 'Montserrat', 'Poppins' => 'Poppins',
                'Raleway' => 'Raleway', 'Nunito' => 'Nunito',
                'Quicksand' => 'Quicksand', 'Space Grotesk' => 'Space Grotesk',
                'Urbanist' => 'Urbanist', 'Sora' => 'Sora', 'Outfit' => 'Outfit',
                'Mulish' => 'Mulish', 'Barlow' => 'Barlow', 'Fredoka' => 'Fredoka',
            ],
            'Serif & editorial' => [
                'Playfair Display' => 'Playfair Display', 'Merriweather' => 'Merriweather',
                'Source Serif Pro' => 'Source Serif Pro', 'Source Serif 4' => 'Source Serif 4',
                'Libre Baskerville' => 'Libre Baskerville', 'Lora' => 'Lora',
                'Roboto Slab' => 'Roboto Slab',
            ],
            'Monospaced' => [
                'JetBrains Mono' => 'JetBrains Mono', 'Fira Code' => 'Fira Code',
                'Source Code Pro' => 'Source Code Pro', 'IBM Plex Mono' => 'IBM Plex Mono',
            ],
            'Expressive' => [
                'Bangers' => 'Bangers', 'Caveat' => 'Caveat',
                'Dancing Script' => 'Dancing Script', 'Pacifico' => 'Pacifico',
                'Oswald' => 'Oswald', 'Fjalla One' => 'Fjalla One',
            ],
        ];
    }

    /** @return array<string,string> */
    public static function fontOptions(): array {
        $options = [];
        foreach (self::fontGroups() as $group) {
            $options += $group;
        }
        return $options;
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
