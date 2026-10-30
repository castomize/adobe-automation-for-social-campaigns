<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const STORAGE_ROOT = APP_ROOT . '/storage';

umask(0077);
ini_set('display_errors', '0');

final class Config
{
    /** Reads literal KEY=value pairs; environment variables take precedence. */
    public static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot read the private .env file.');
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(CLOUDFLARE_ACCOUNT_ID|CLOUDFLARE_API_TOKEN)=(.*)$/D', $line, $match)) {
                throw new RuntimeException('Invalid .env syntax. Use the two keys in .env.example.');
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($match[1]) === false) {
                putenv($match[1] . '=' . $value);
            }
        }
    }

    public static function credentials(): ?array
    {
        $account = getenv('CLOUDFLARE_ACCOUNT_ID') ?: '';
        $token = getenv('CLOUDFLARE_API_TOKEN') ?: '';
        if ($account === '' || $token === '' || str_starts_with($account, 'replace_') || str_starts_with($token, 'replace_')) {
            return null;
        }
        return ['account' => $account, 'token' => $token];
    }

    public static function prerequisites(): array
    {
        return [
            'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
            'cURL' => extension_loaded('curl'),
            'fileinfo' => extension_loaded('fileinfo'),
            'GD' => extension_loaded('gd'),
            'ZIP' => class_exists('ZipArchive'),
            'FreeType' => function_exists('gd_info') && (gd_info()['FreeType Support'] ?? false),
            'Bundled Lato fonts' => is_file(APP_ROOT . '/assets/fonts/Lato-Regular.ttf') && is_file(APP_ROOT . '/assets/fonts/Lato-Bold.ttf'),
            'Bundled display font' => is_file(APP_ROOT . '/assets/fonts/DMSerifDisplay-Regular.ttf'),
        ];
    }
}

final class Storage
{
    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private storage could not be created.');
        }
    }

    public static function write(string $path, string $bytes): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new RuntimeException('Private storage write failed.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function json(string $path, array $data): void
    {
        self::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}

Config::loadEnv(APP_ROOT . '/.env');
require_once __DIR__ . '/Input.php';
require_once __DIR__ . '/CloudflareClient.php';
require_once __DIR__ . '/Renderer.php';
require_once __DIR__ . '/Campaign.php';
require_once __DIR__ . '/Runs.php';
