<?php
declare(strict_types=1);

/**
 * Reads config from real environment variables (Render, Docker) and, for local
 * development without Docker, from a .env file in the project root.
 */
(function (): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
})();

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);
    return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Path to a CA certificate bundle for TLS (Cloudinary, TiDB).
 * Linux/Docker has one built in. On Windows/XAMPP download https://curl.se/ca/cacert.pem
 * and either set CA_BUNDLE in .env or curl.cainfo in php.ini.
 */
function ca_bundle(): ?string
{
    $candidates = [
        env('CA_BUNDLE'),
        env('DB_SSL_CA'),
        ini_get('curl.cainfo') ?: null,
        ini_get('openssl.cafile') ?: null,
        '/etc/ssl/certs/ca-certificates.crt',   // Debian/Ubuntu (Docker image)
        '/etc/pki/tls/certs/ca-bundle.crt',     // RHEL/Fedora
        '/opt/homebrew/etc/ca-certificates/cert.pem', // macOS (Homebrew)
        'C:\\xampp\\php\\extras\\ssl\\cacert.pem',  // XAMPP default location
    ];
    foreach ($candidates as $path) {
        if ($path && is_file($path)) {
            return $path;
        }
    }
    return null;
}
