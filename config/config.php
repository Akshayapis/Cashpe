<?php
/**
 * CashPe - Global configuration, session bootstrap and shared helpers.
 */

// Never leak raw errors to the browser
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('CASHPE_ROOT', dirname(__DIR__));

// Composer autoloader (kreait/firebase-php, phpmailer, php-qrcode). Absent in
// local lint environments; present in the Docker build after `composer install`.
$autoload = CASHPE_ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

// ---------------------------------------------------------------------------
// Minimal .env loader (real environment variables win over .env file values)
// ---------------------------------------------------------------------------
$GLOBALS['ENV'] = [];
$envFile = CASHPE_ROOT . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Strip surrounding quotes if present
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $GLOBALS['ENV'][$key] = $value;
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }
    $fromFile = $GLOBALS['ENV'][$key] ?? null;
    if ($fromFile !== null && $fromFile !== '') {
        return $fromFile;
    }
    return $default;
}

define('APP_NAME', 'CashPe');
define('APP_TAGLINE', 'Receive Payments. Anytime. Anywhere.');
define('APP_URL', rtrim((string) env('APP_URL', 'https://cashpe.onrender.com'), '/'));
define('APP_ENV', (string) env('APP_ENV', 'production'));
define('ENCRYPTION_KEY', (string) env('ENCRYPTION_KEY', 'cashpe-insecure-dev-key-change-me'));
define('GOOGLE_CLIENT_ID', (string) env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', (string) env('GOOGLE_CLIENT_SECRET', ''));
define('ADMIN_EMAIL', (string) env('ADMIN_EMAIL', ''));
define('ADMIN_PASSWORD', (string) env('ADMIN_PASSWORD', ''));

// ---------------------------------------------------------------------------
// Session (httponly, samesite, secure when behind HTTPS)
// ---------------------------------------------------------------------------
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

if (session_status() === PHP_SESSION_NONE) {
    session_name('cashpe_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function generate_api_key(): string
{
    return 'cp_live_' . bin2hex(random_bytes(20));
}

function generate_link_id(): string
{
    return 'pl_' . bin2hex(random_bytes(10));
}

function generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function format_money(mixed $amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function format_date(mixed $ts): string
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '-';
    }
    return date('d M Y, h:i A', $ts);
}

/** Flash messages (survive one redirect) */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** AES-256-GCM encryption helpers (for provider session tokens at rest) */
function encrypt_secret(string $plain): string
{
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $payload): string|false
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) {
        return false;
    }
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    return openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
}

function client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function current_user_agent_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
}
