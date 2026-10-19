<?php
// Limit post-login navigation to existing application pages beneath this install.
final class LoginDestination
{
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return null;
        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) return null;
        $path = $parts['path'] ?? '';
        if (str_contains($path, '%') || str_contains($path, '..')) return null;
        $isFrontend = preg_match('~^frontend/[a-z0-9_-]+\.html$~D', $path) === 1;
        if (!$isFrontend && !in_array($path, ['settings.php', 'users.php', 'totp_setup.php'], true)) return null;
        if (!is_file(dirname(__DIR__) . '/' . $path)) return null;
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function consume(): string
    {
        $destination = self::validate($_SESSION['login_return'] ?? null) ?? 'frontend/instant.html';
        unset($_SESSION['login_return']);
        return $destination;
    }
}
