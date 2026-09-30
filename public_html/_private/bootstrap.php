<?php
// Shared setup for every PHP endpoint: config, database, errors, helpers.
declare(strict_types=1);

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/start_dates.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/layout.php';

function fb_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = getenv('FB_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($file)) {
            error_log('Familiebøger: config.php is missing');
            fb_error_page(500, 'Siden er ikke sat op endnu', 'Prøv igen senere.');
        }
        $config = require $file;
    }
    return $config;
}

function fb_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = fb_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'],
            (int) ($c['port'] ?? 3306),
            $c['name']
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // All DATETIME columns are stored in UTC.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function fb_timezone(): DateTimeZone
{
    return new DateTimeZone(fb_config()['timezone'] ?? 'Europe/Copenhagen');
}

function fb_utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fb_wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function fb_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!preg_grep('/^Cache-Control:/i', headers_list())) {
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fb_redirect(string $url, int $status = 303): void
{
    header('Location: ' . $url, true, $status);
    exit;
}

function fb_error_page(int $status, string $title, string $message, string $linkHref = './', string $linkText = 'Til forsiden'): void
{
    http_response_code($status);
    fb_page_start($title);
    echo '<section class="wrap flow"><div class="flow__inner">';
    echo '<h1 class="flow__title">' . h($title) . '</h1>';
    echo '<p class="lead">' . h($message) . '</p>';
    echo '<p><a class="btn btn--primary" href="' . h($linkHref) . '">' . h($linkText) . '</a></p>';
    echo '</div></section>';
    fb_page_end();
    exit;
}

// Never show stack traces to visitors; log them for the hosting error log.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('Familiebøger: ' . $e);
    $debug = false;
    try {
        $debug = !empty(fb_config()['debug']);
    } catch (Throwable $ignored) {
    }
    if (!headers_sent()) {
        if (fb_wants_json()) {
            fb_json(500, ['ok' => false, 'message' => 'Noget gik galt. Prøv igen om lidt.']);
        }
        fb_error_page(500, 'Noget gik galt', $debug ? (string) $e : 'Prøv igen om lidt. Hvis det bliver ved, så skriv til os.');
    }
});

date_default_timezone_set('UTC');
