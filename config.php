<?php
/**
 * Sodai Lagbe ERP - Environment Configuration Loader
 * .env ফাইল থেকে environment variables load করে।
 * db.php এবং অন্যান্য ফাইল এটি require করবে।
 */

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            die('CRITICAL ERROR: .env file not found. Please create .env from .env.example — Path: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;

            list($key, $value) = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim(trim($value), '"\'');

            if (!empty($key)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// .env path নির্ধারণ — root থেকে এবং admin/ subdirectory থেকে দুটোই কাজ করবে
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    $envPath = __DIR__ . '/../.env';
}

loadEnv($envPath);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Dhaka');
