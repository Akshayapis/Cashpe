<?php
/**
 * CashPe - Authentication: user sessions, admin sessions, guards.
 */

require_once __DIR__ . '/firebase_helper.php';

function auth_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['uid'] ?? $user['id'] ?? '';
    $_SESSION['ua'] = current_user_agent_hash();
    unset($_SESSION['oauth_state']);
}

function auth_uid(): ?string
{
    $uid = $_SESSION['uid'] ?? null;
    return (is_string($uid) && $uid !== '') ? $uid : null;
}

/** Current Firestore user document for the session, or null. Cached per request. */
function auth_user(bool $fresh = false): ?array
{
    static $cache = null;
    static $cachedFresh = false;
    if ($cache !== null && !$fresh && !$cachedFresh) {
        return $cache;
    }
    if ($fresh && $cache !== null) {
        $cachedFresh = true;
    }
    $uid = auth_uid();
    if ($uid === null) {
        return null;
    }
    try {
        $user = user_get($uid);
    } catch (Throwable $e) {
        error_log('auth_user failed: ' . $e->getMessage());
        return $cache; // keep last known user on transient DB failures
    }
    $cache = $user;
    return $cache;
}

function auth_require(): array
{
    $uid = auth_uid();
    if ($uid === null) {
        redirect('index.php');
    }
    // Session hijacking mitigation: bind session to a stable UA hash
    $ua = $_SESSION['ua'] ?? null;
    if ($ua !== null && !hash_equals($ua, current_user_agent_hash())) {
        auth_logout();
        redirect('index.php');
    }
    try {
        $user = auth_user();
    } catch (Throwable $e) {
        $user = null;
    }
    if ($user === null) {
        auth_logout();
        redirect('index.php');
    }
    return $user;
}

function auth_require_verified(): array
{
    $user = auth_require();
    if (empty($user['email_verified'])) {
        flash_set('error', 'Please verify your email address first. Check your inbox for the magic link.');
        redirect('index.php?resend=1');
    }
    if (($user['status'] ?? 'active') === 'blocked') {
        auth_logout();
        flash_set('error', 'Your account has been suspended. Contact support@cashpe.onrender.com');
        redirect('index.php');
    }
    return $user;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

// ----- Admin sessions (email + password from .env) -----

function admin_is_logged_in(): bool
{
    return ($_SESSION['admin'] ?? false) === true
        && ($_SESSION['admin_ua'] ?? '') === current_user_agent_hash();
}

function admin_login(string $email, string $password): bool
{
    if (ADMIN_EMAIL === '' || ADMIN_PASSWORD === '') {
        error_log('Admin login attempted but ADMIN_EMAIL / ADMIN_PASSWORD are not set.');
        return false;
    }
    $ok = hash_equals(strtolower(ADMIN_EMAIL), strtolower(trim($email)))
        && hash_equals(ADMIN_PASSWORD, $password);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_ua'] = current_user_agent_hash();
    } else {
        usleep(700000); // slow down brute force
    }
    return $ok;
}

function admin_logout(): void
{
    unset($_SESSION['admin'], $_SESSION['admin_ua']);
}
